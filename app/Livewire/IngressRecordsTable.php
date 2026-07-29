<?php

namespace App\Livewire;

use App\Jobs\PublishEdgeRoutes;
use App\Models\AppRoute;
use App\Models\IngressSetting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Ingress-records tab: CRUD over App\Models\AppRoute (app -> host -> ip:port),
 * and every mutation fires the async publish so a saved record lights up CoreDNS
 * + the owned Caddy edge with a live spinner + build console (the N2 Reverb rail),
 * never a locked-up page. Standalone Livewire table (a Page hosts only one
 * table(); those are Networks/Profiles), so the proven tabs are untouched.
 *
 * Publishing routes through the currently-selected provider (IngressManager): on
 * `edge` it streams the caddy+resolver build; on `managed` it re-asserts DNS; on
 * `manual` it is a no-op. The tab is where the operator's "add the records, on
 * save update CoreDNS and the caddy config, with a spinner" lands.
 */
class IngressRecordsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Non-empty while a publish is streaming to the toast/console. */
    public string $publishToken = '';

    public bool $publishing = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(AppRoute::query()->orderBy('app'))
            ->emptyStateHeading(__('ingress.records.empty_heading'))
            ->emptyStateDescription(__('ingress.records.empty_description'))
            ->columns([
                TextColumn::make('app')
                    ->label(__('ingress.records.app'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('host')
                    ->label(__('ingress.records.host')),
                TextColumn::make('ip')
                    ->label(__('ingress.records.ip'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('port')
                    ->label(__('ingress.records.port')),
                TextColumn::make('live_instance')
                    ->label(__('ingress.records.instance'))
                    ->placeholder('—'),
            ])
            ->defaultSort('app')
            ->headerActions([
                Action::make('createRecord')
                    ->label(__('ingress.records.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema(fn () => $this->recordForm())
                    ->action(function (array $data): void {
                        $settings = IngressSetting::current();
                        AppRoute::query()->updateOrCreate(
                            ['app' => $data['app']],
                            [
                                'host' => ($data['host'] ?? '') !== '' ? $data['host'] : $settings->hostFor($data['app']),
                                'ip' => $data['ip'],
                                'port' => (int) $data['port'],
                                'live_instance' => $data['live_instance'] ?? null,
                            ],
                        );
                        Notification::make()->title(__('ingress.records.created'))->success()->send();
                        $this->publishNow();
                    }),
                Action::make('publishNow')
                    ->label(__('ingress.records.publish'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(fn () => $this->publishNow()),
            ])
            ->recordActions([
                Action::make('editRecord')
                    ->label(__('ingress.records.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (AppRoute $record): array => [
                        'app' => $record->app,
                        'host' => $record->host,
                        'ip' => $record->ip,
                        'port' => $record->port,
                        'live_instance' => $record->live_instance,
                    ])
                    ->schema(fn (AppRoute $record) => $this->recordForm(editing: true))
                    ->action(function (AppRoute $record, array $data): void {
                        $record->update([
                            'host' => ($data['host'] ?? '') !== '' ? $data['host'] : IngressSetting::current()->hostFor($record->app),
                            'ip' => $data['ip'],
                            'port' => (int) $data['port'],
                            'live_instance' => $data['live_instance'] ?? null,
                        ]);
                        Notification::make()->title(__('ingress.records.updated'))->success()->send();
                        $this->publishNow();
                    }),
                Action::make('deleteRecord')
                    ->label(__('ingress.records.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('ingress.records.delete_confirm'))
                    ->action(function (AppRoute $record): void {
                        $record->delete();
                        Notification::make()->title(__('ingress.records.deleted'))->success()->send();
                        $this->publishNow();
                    }),
            ]);
    }

    /**
     * The shared record form. `app` is the key and is locked on edit (rename =
     * delete + recreate). `host` defaults to <app>.<zone> when left blank.
     *
     * @return array<int,\Filament\Forms\Components\Component>
     */
    private function recordForm(bool $editing = false): array
    {
        $settings = IngressSetting::current();

        return [
            TextInput::make('app')
                ->label(__('ingress.records.app'))
                ->required()
                ->alphaDash()
                ->disabled($editing)
                ->helperText(__('ingress.records.app_help')),
            TextInput::make('host')
                ->label(__('ingress.records.host'))
                ->placeholder($settings->hostFor('<app>'))
                ->helperText(__('ingress.records.host_help')),
            TextInput::make('ip')
                ->label(__('ingress.records.ip'))
                ->required()
                ->helperText(__('ingress.records.ip_help')),
            TextInput::make('port')
                ->label(__('ingress.records.port'))
                ->numeric()
                ->required()
                ->default($settings->app_port)
                ->helperText(__('ingress.records.port_help')),
            TextInput::make('live_instance')
                ->label(__('ingress.records.instance'))
                ->helperText(__('ingress.records.instance_help')),
        ];
    }

    /** Dispatch the async publish and flip the spinner on. */
    public function publishNow(): void
    {
        $this->publishToken = (string) Str::random(24);
        $this->publishing = true;

        PublishEdgeRoutes::dispatch($this->publishToken, Auth::id());
    }

    /** The Alpine toast fires this on the terminal (done/failed) phase. */
    #[On('network-provisioned')]
    public function onPublished(): void
    {
        $this->publishing = false;
    }

    public function render()
    {
        return view('livewire.ingress-records-table');
    }
}
