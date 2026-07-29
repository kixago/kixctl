<?php

namespace App\Filament\Pages;

use App\Jobs\ProvisionManagedNetwork;
use App\Models\IngressSetting;
use App\Models\Network;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\IngressManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * GUI for the ingress seam. Opens pre-filled with the managed defaults so the
 * "leave it alone" operator never has to think about it; switch the provider to
 * `manual` to integrate your own DNS. "Back to defaults" re-seeds from
 * config/ingress.php and re-asserts the managed provider — kixctl takes over
 * again, exactly as intended.
 */
class IngressSettings extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected string $view = 'filament.pages.ingress-settings';

    public ?array $data = [];

    public array $status = [];

    /** Non-empty while a managed-network provision is streaming to the toast. */
    public string $provisionToken = '';

    public bool $provisioning = false;

    /** Active Settings tab: network|ingress (storage is coming-soon). */
    public string $tab = 'network';

    /**
     * Resolver create-first state, refreshed from Incus:
     *   ['state' => absent|provisioning|ready, 'instance', 'ip'?, 'network'?].
     * Drives the Network tab: absent → Create; provisioning → console; ready → config.
     */
    public array $resolver = ['state' => 'absent'];

    /** Keep the page visible without a bespoke Shield permission for now. */
    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.title');
    }

    public function mount(): void
    {
        $this->form->fill(IngressSetting::current()->attributesToArray());
        $this->refreshStatus();
        $this->refreshResolver();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('ingress.section.general'))
                    ->description(__('ingress.section.general_help'))
                    ->components([
                        Select::make('provider')
                            ->label(__('ingress.form.provider'))
                            ->required()
                            ->live()
                            ->options([
                                'managed' => __('ingress.provider.managed'),
                                'manual' => __('ingress.provider.manual'),
                            ])
                            ->helperText(__('ingress.form.provider_help')),

                        TextInput::make('zone')
                            ->label(__('ingress.form.zone'))
                            ->required()
                            ->helperText(__('ingress.form.zone_help')),

                        TextInput::make('app_port')
                            ->label(__('ingress.form.app_port'))
                            ->numeric()
                            ->required()
                            ->helperText(__('ingress.form.app_port_help')),
                    ]),

                Section::make(__('ingress.section.managed'))
                    ->description(__('ingress.section.managed_help'))
                    ->visible(fn (Get $get) => $get('provider') === 'managed')
                    ->components([
                        TextInput::make('dns_instance')
                            ->label(__('ingress.form.dns_instance'))
                            ->required(),
                        TextInput::make('dns_target')
                            ->label(__('ingress.form.dns_target'))
                            ->required()
                            ->helperText(__('ingress.form.dns_target_help')),
                        TextInput::make('dns_network')
                            ->label(__('ingress.form.dns_network'))
                            ->placeholder(__('ingress.form.dns_network_placeholder'))
                            ->helperText(__('ingress.form.dns_network_help')),
                        TextInput::make('dns_refresh')
                            ->label(__('ingress.form.dns_refresh'))
                            ->required()
                            ->helperText(__('ingress.form.dns_refresh_help')),
                        TextInput::make('record_ttl')
                            ->label(__('ingress.form.record_ttl'))
                            ->numeric()
                            ->required(),
                    ]),

                Section::make(__('ingress.section.manual'))
                    ->description(__('ingress.section.manual_help'))
                    ->visible(fn (Get $get) => $get('provider') === 'manual')
                    ->components([
                        TextInput::make('byo_endpoint')
                            ->label(__('ingress.form.byo_endpoint'))
                            ->placeholder(__('ingress.form.byo_endpoint_placeholder'))
                            ->helperText(__('ingress.form.byo_endpoint_help')),
                        TextInput::make('byo_token')
                            ->label(__('ingress.form.byo_token'))
                            ->password()
                            ->revealable()
                            ->helperText(__('ingress.form.byo_token_help')),
                    ]),
            ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('ingress.action.save'))
            ->submit('save');
    }

    public function defaultsAction(): Action
    {
        return Action::make('defaults')
            ->label(__('ingress.action.defaults'))
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('ingress.action.defaults_confirm'))
            ->action(fn () => $this->backToDefaults());
    }

    /** Create-first: explicitly stand up the resolver (absent → provisioning). */
    public function createResolverAction(): Action
    {
        return Action::make('createResolver')
            ->label(__('networks.action.create'))
            ->icon(Heroicon::OutlinedBolt)
            ->action(fn () => $this->provisionManaged());
    }

    /** Rebuild: delete + reprovision a broken/stale/flake-changed resolver. */
    public function rebuildResolverAction(): Action
    {
        return Action::make('rebuildResolver')
            ->label(__('networks.action.rebuild'))
            ->color('gray')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription(__('networks.action.rebuild_confirm'))
            ->action(fn () => $this->provisionManaged(rebuild: true));
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = IngressSetting::current();
        $settings->fill($data)->save();

        // Create-first: saving settings no longer provisions as a side effect
        // (that was the confusing save-first path). Provisioning is explicit now,
        // via Create/Rebuild resolver on the Network tab.
        $this->refreshStatus();
        $this->refreshResolver();

        Notification::make()
            ->title(__('ingress.saved'))
            ->success()
            ->send();
    }

    public function backToDefaults(): void
    {
        $settings = IngressSetting::current()->resetToDefaults();
        $this->form->fill($settings->attributesToArray());

        $this->refreshStatus();
        $this->refreshResolver();

        Notification::make()
            ->title(__('ingress.reset'))
            ->success()
            ->send();
    }

    /** Toast finished (done or failed) — clear the streaming state, refresh status. */
    #[On('network-provisioned')]
    public function onProvisioned(): void
    {
        $this->provisioning = false;
        $this->refreshStatus();
        $this->refreshResolver();
    }

    public function refreshStatus(): void
    {
        $this->status = app(IngressManager::class)->status();
    }

    /**
     * Create-first state, read live from Incus. absent = no resolver instance;
     * provisioning = exists but no lease yet; ready = exists + has an IPv4.
     * No cluster / no instance both read as absent (nothing to configure yet).
     */
    public function refreshResolver(): void
    {
        $name = IngressSetting::current()->dns_instance;

        $registry = app(ClusterRegistry::class);
        $incus = app(IncusClient::class);

        try {
            $cluster = collect($registry->all())->first();

            if (! $cluster || ! $incus->instanceExists($cluster, $name)) {
                $this->resolver = ['state' => 'absent', 'instance' => $name];

                return;
            }

            $ip = $incus->instanceIpv4($cluster, $name);
        } catch (\Throwable) {
            // Incus unreachable / transient — don't blank the page; treat as absent.
            $this->resolver = ['state' => 'absent', 'instance' => $name];

            return;
        }

        $this->resolver = [
            'state' => ($ip !== null && $ip !== '') ? 'ready' : 'provisioning',
            'instance' => $name,
            'ip' => $ip,
            'network' => Network::default()?->key,
        ];
    }

    /**
     * Kick off managed-network provisioning on the queue and open the live toast.
     * The heavy work (createNetwork → build → launch → lease → serve) runs in
     * ProvisionManagedNetwork and streams over Reverb, so the request returns at
     * once and the user watches a corner toast instead of a frozen spinner.
     */
    private function provisionManaged(bool $rebuild = false): void
    {
        $this->provisionToken = (string) Str::random(24);
        $this->provisioning = true;
        $this->resolver['state'] = 'provisioning';

        ProvisionManagedNetwork::dispatch(
            $this->provisionToken,
            (string) config('deploy.launch.cluster', '') ?: null,
            Auth::id(),
            $rebuild,
        );
    }
}
