<?php

namespace App\Filament\Pages;

use App\Jobs\ProvisionManagedNetwork;
use App\Models\IngressSetting;
use App\Models\Network;
use App\Services\Ingress\IngressManager;
use App\Services\Networks\NetworkManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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
class IngressSettings extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

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

    /** Per-request memo of live Incus network state (not Livewire-tracked). */
    private ?array $liveNetworksCache = null;

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

        $registry = app(\App\Services\Incus\ClusterRegistry::class);
        $incus = app(\App\Services\Incus\IncusClient::class);

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
            'network' => \App\Models\Network::default()?->key,
        ];
    }

    /**
     * Kick off managed-network provisioning on the queue and open the live toast.
     * The heavy work (createNetwork → build → launch → lease → serve) runs in
     * ProvisionManagedNetwork and streams over Reverb, so the request returns at
     * once and the user watches a corner toast instead of a frozen spinner.
     */
    /**
     * The Networks table (managed rows + the locked kixbr0, shown and guarded).
     * Create/Delete route through NetworkManager so the real Incus bridge is kept
     * in lockstep with the row; the model guard protects the locked default.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(Network::query()->orderBy('sort')->orderBy('id'))
            ->columns([
                TextColumn::make('key')
                    ->label(__('networks.table.key'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('label')
                    ->label(__('networks.table.label')),
                TextColumn::make('ipv4_cidr')
                    ->label(__('networks.table.subnet'))
                    ->state(fn (Network $record) => $record->managed
                        ? ($record->ipv4_cidr ?: __('networks.table.auto'))
                        : ($this->liveNetworks()[$record->key]['ipv4_address'] ?? '—'))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('ipv4_nat')
                    ->label(__('networks.table.nat'))
                    ->state(fn (Network $record) => $record->managed
                        ? (bool) $record->ipv4_nat
                        : (bool) ($this->liveNetworks()[$record->key]['ipv4_nat'] ?? false))
                    ->boolean(),
                IconColumn::make('ipv4_dhcp')
                    ->label(__('networks.table.dhcp'))
                    ->state(fn (Network $record) => $record->managed
                        ? (bool) $record->ipv4_dhcp
                        : (bool) ($this->liveNetworks()[$record->key]['ipv4_dhcp'] ?? false))
                    ->boolean(),
                TextColumn::make('isolation')
                    ->label(__('networks.table.isolation'))
                    ->badge(),
                IconColumn::make('is_default')
                    ->label(__('networks.table.default'))
                    ->boolean(),
                TextColumn::make('used_by')
                    ->label(__('networks.table.used_by'))
                    ->state(fn (Network $record) => $this->liveNetworks()[$record->key]['used_by'] ?? '—')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('managed')
                    ->label(__('networks.table.managed'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_locked')
                    ->label(__('networks.table.locked'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->headerActions([
                Action::make('createNetwork')
                    ->label(__('networks.crud.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        TextInput::make('key')
                            ->label(__('networks.form.key'))
                            ->required()
                            ->alphaDash()
                            ->helperText(__('networks.form.key_help')),
                        TextInput::make('label')
                            ->label(__('networks.form.label'))
                            ->required(),
                        Textarea::make('description')
                            ->label(__('networks.form.description'))
                            ->rows(2)
                            ->helperText(__('networks.form.description_help')),
                        TextInput::make('ipv4_cidr')
                            ->label(__('networks.form.cidr'))
                            ->placeholder(__('networks.table.auto'))
                            ->helperText(__('networks.form.cidr_help')),
                        Toggle::make('ipv4_nat')
                            ->label(__('networks.form.nat'))
                            ->default(true),
                        Toggle::make('ipv4_dhcp')
                            ->label(__('networks.form.dhcp'))
                            ->default(true),
                        Select::make('isolation')
                            ->label(__('networks.form.isolation'))
                            ->options(array_combine(Network::ISOLATIONS, Network::ISOLATIONS))
                            ->default('open')
                            ->required(),
                        Toggle::make('is_default')
                            ->label(__('networks.form.is_default'))
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(NetworkManager::class)->create($data);
                            Notification::make()->title(__('networks.crud.created'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('networks.crud.create_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('registerNetwork')
                    ->label(__('networks.crud.register'))
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->schema([
                        Select::make('key')
                            ->label(__('networks.form.existing'))
                            ->options(fn () => $this->registerableNetworks())
                            ->required()
                            ->helperText(__('networks.form.existing_help')),
                        TextInput::make('label')
                            ->label(__('networks.form.label'))
                            ->required(),
                        Textarea::make('description')
                            ->label(__('networks.form.description'))
                            ->rows(2)
                            ->helperText(__('networks.form.description_help')),
                        Select::make('isolation')
                            ->label(__('networks.form.isolation'))
                            ->options(array_combine(Network::ISOLATIONS, Network::ISOLATIONS))
                            ->default('open')
                            ->required(),
                        Toggle::make('is_default')
                            ->label(__('networks.form.is_default'))
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(NetworkManager::class)->register($data);
                            Notification::make()->title(__('networks.crud.registered'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('networks.crud.register_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('editNetwork')
                    ->label(__('networks.crud.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Network $record) => ! $record->is_locked)
                    ->fillForm(function (Network $record): array {
                        $live = $this->liveNetworks()[$record->key] ?? [];
                        $unmanaged = ! $record->managed;

                        return [
                            'key' => $record->key,
                            'label' => $record->label,
                            'description' => $record->description,
                            // Unmanaged: show the REAL bridge's live values (read-only).
                            // Managed: the row is the source of truth.
                            'ipv4_cidr' => $unmanaged ? ($live['ipv4_address'] ?? '') : $record->ipv4_cidr,
                            'ipv4_nat' => $unmanaged ? (bool) ($live['ipv4_nat'] ?? false) : (bool) $record->ipv4_nat,
                            'ipv4_dhcp' => $unmanaged ? (bool) ($live['ipv4_dhcp'] ?? false) : (bool) $record->ipv4_dhcp,
                            'isolation' => $record->isolation,
                        ];
                    })
                    ->schema(function (Network $record): array {
                        $unmanaged = ! $record->managed;

                        return [
                            TextInput::make('key')
                                ->label(__('networks.form.key'))
                                ->disabled()
                                ->helperText(__('networks.form.key_locked_help')),
                            TextInput::make('label')
                                ->label(__('networks.form.label'))
                                ->required(),
                            Textarea::make('description')
                                ->label(__('networks.form.description'))
                                ->rows(2)
                                ->helperText(__('networks.form.description_help')),
                            TextInput::make('ipv4_cidr')
                                ->label(__('networks.form.cidr'))
                                ->placeholder(__('networks.table.auto'))
                                ->disabled()
                                ->helperText($unmanaged ? __('networks.form.cidr_unmanaged_help') : __('networks.form.cidr_locked_help')),
                            Toggle::make('ipv4_nat')
                                ->label(__('networks.form.nat'))
                                ->disabled($unmanaged)
                                ->helperText($unmanaged ? __('networks.form.owned_by_bridge') : null),
                            Toggle::make('ipv4_dhcp')
                                ->label(__('networks.form.dhcp'))
                                ->disabled($unmanaged)
                                ->helperText($unmanaged ? __('networks.form.owned_by_bridge') : null),
                            Select::make('isolation')
                                ->label(__('networks.form.isolation'))
                                ->options(array_combine(Network::ISOLATIONS, Network::ISOLATIONS))
                                ->required(),
                        ];
                    })
                    ->action(function (Network $record, array $data): void {
                        try {
                            app(NetworkManager::class)->update($record, $data);
                            Notification::make()->title(__('networks.crud.updated'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('networks.crud.update_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('setDefault')
                    ->label(__('networks.crud.set_default'))
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (Network $record) => ! $record->is_default)
                    ->action(function (Network $record): void {
                        app(NetworkManager::class)->setDefault($record);
                        Notification::make()->title(__('networks.crud.default_set'))->success()->send();
                    }),
                Action::make('deleteNetwork')
                    ->label(__('networks.crud.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Network $record) => ! $record->is_locked)
                    ->action(function (Network $record): void {
                        try {
                            app(NetworkManager::class)->delete($record);
                            Notification::make()->title(__('networks.crud.deleted'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('networks.crud.delete_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * Live Incus network state, keyed by name, fetched ONCE per request and
     * memoized. Degrades to an empty map if the cluster is unreachable, so the
     * table still renders (the used-by column just shows —).
     *
     * @return array<string,array<string,mixed>>
     */
    private function liveNetworks(): array
    {
        if ($this->liveNetworksCache !== null) {
            return $this->liveNetworksCache;
        }

        try {
            $registry = app(\App\Services\Incus\ClusterRegistry::class);
            $incus = app(\App\Services\Incus\IncusClient::class);
            $cluster = collect($registry->all())->first();

            $map = [];
            if ($cluster) {
                foreach ($incus->networks($cluster) as $n) {
                    $map[$n['name']] = $n;
                }
            }

            return $this->liveNetworksCache = $map;
        } catch (\Throwable) {
            return $this->liveNetworksCache = [];
        }
    }

    /**
     * Unmanaged networks already on the cluster that aren't yet referenced by a
     * kixctl row — the pickable targets for "Register existing". Skips loopback
     * and physical interfaces (nothing to place instances on).
     *
     * @return array<string,string>
     */
    private function registerableNetworks(): array
    {
        $taken = Network::query()->pluck('key')->all();

        $out = [];
        foreach ($this->liveNetworks() as $name => $n) {
            if (($n['managed'] ?? false) !== false) {
                continue; // kixctl-managed or Incus-managed already
            }
            if (in_array($n['type'] ?? '', ['loopback', 'physical'], true)) {
                continue; // can't target these
            }
            if (in_array($name, $taken, true)) {
                continue; // already referenced
            }
            $out[$name] = $name.' ('.($n['type'] ?? '?').', '.($n['used_by'] ?? 0).' in use)';
        }

        return $out;
    }

    private function provisionManaged(bool $rebuild = false): void
    {
        $this->provisionToken = (string) Str::random(24);
        $this->provisioning = true;

        ProvisionManagedNetwork::dispatch(
            $this->provisionToken,
            (string) config('deploy.launch.cluster', '') ?: null,
            Auth::id(),
            $rebuild,
        );
    }
}
