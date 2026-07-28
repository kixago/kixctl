<?php

namespace App\Filament\Pages;

use App\Models\IngressSetting;
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

    /** Keep the page visible without a bespoke Shield permission for now. */
    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public static function getNavigationLabel(): string
    {
        return __('ingress.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ingress.title');
    }

    public function mount(): void
    {
        $this->form->fill(IngressSetting::current()->attributesToArray());
        $this->refreshStatus();
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

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = IngressSetting::current();
        $settings->fill($data)->save();

        // If kixctl is managing DNS, re-assert the full zone from current routes.
        if ($settings->isManaged()) {
            $this->safeSync();
        }

        $this->refreshStatus();

        Notification::make()
            ->title(__('ingress.saved'))
            ->success()
            ->send();
    }

    public function backToDefaults(): void
    {
        $settings = IngressSetting::current()->resetToDefaults();
        $this->form->fill($settings->attributesToArray());

        if ($settings->isManaged()) {
            $this->safeSync();
        }

        $this->refreshStatus();

        Notification::make()
            ->title(__('ingress.reset'))
            ->success()
            ->send();
    }

    public function refreshStatus(): void
    {
        $this->status = app(IngressManager::class)->status();
    }

    /** Publishing touches the cluster; surface failures instead of 500ing the page. */
    private function safeSync(): void
    {
        try {
            app(IngressManager::class)->syncAll();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('ingress.sync_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
