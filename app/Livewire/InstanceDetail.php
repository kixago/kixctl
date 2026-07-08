<?php

namespace App\Livewire;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\On;
use Livewire\Component;

class InstanceDetail extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public bool $open = false;
    public string $cluster = '';
    public string $name = '';

    public array $detail = [];
    public array $snapshots = [];
    public array $config = [];

    #[On('open-instance-detail')]
    public function openFor(string $cluster, string $name): void
    {
        $this->cluster = $cluster;
        $this->name = $name;
        $this->open = true;
        $this->refreshData();
    }

    public function close(): void
    {
        $this->open = false;
    }

    protected function refreshData(): void
    {
        $target = app(ClusterRegistry::class)->find($this->cluster);
        if (! $target) {
            return;
        }
        $incus = app(IncusClient::class);
        $this->detail = $incus->instance($target, $this->name);
        $this->snapshots = $incus->snapshots($target, $this->name);
        $this->config = $incus->instanceConfig($target, $this->name);
    }

    protected function target()
    {
        return app(ClusterRegistry::class)->find($this->cluster);
    }

    /** Create snapshot — soft confirm (non-destructive). */
    public function createSnapshotAction(): Action
    {
        return Action::make('createSnapshot')
            ->label('New snapshot')
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->schema([
                TextInput::make('snapshot')
                    ->label('Snapshot name')
                    ->default(fn() => $this->name . '-' . now()->format('Ymd-His'))
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (array $data) {
                try {
                    app(IncusClient::class)->createSnapshot($this->target(), $this->name, $data['snapshot']);
                    Notification::make()->title('Snapshot created')->body($data['snapshot'])->success()->send();
                    $this->refreshData();
                } catch (\Throwable $e) {
                    Notification::make()->title('Snapshot failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Restore — STRONG guard: type the instance name. Destructive. */
    public function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Restore snapshot')
            ->modalDescription(fn(array $arguments)
                => "This reverts “{$this->name}” entirely to snapshot “{$arguments['snapshot']}”, including its data. This cannot be undone.")
            ->schema([
                TextInput::make('confirm')
                    ->label("Type the instance name (“{$this->name}”) to confirm")
                    ->required()
                    ->rule(fn() => function ($attr, $value, $fail) {
                        if ($value !== $this->name) {
                            $fail('Name does not match.');
                        }
                    }),
            ])
            ->action(function (array $arguments) {
                try {
                    app(IncusClient::class)->restoreSnapshot($this->target(), $this->name, $arguments['snapshot']);
                    Notification::make()->title('Restored')->body($arguments['snapshot'])->success()->send();
                    $this->refreshData();
                    $this->dispatch('instance-changed'); // tell the table to reload
                } catch (\Throwable $e) {
                    Notification::make()->title('Restore failed')->body($e->getMessage())->danger()->send();
                }
            });
    }
    /** Delete the whole instance — STRONG guard: type the instance name. Destructive. */
    public function deleteInstanceAction(): Action
    {
        return Action::make('deleteInstance')
            ->label('Delete instance')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete instance')
            ->modalDescription(fn() => "This permanently deletes “{$this->name}” and its root filesystem. Attached volumes that persist are not removed. This cannot be undone.")
            ->schema([
                TextInput::make('confirm')
                    ->label("Type the instance name (“{$this->name}”) to confirm")
                    ->required()
                    ->rule(fn() => function ($attr, $value, $fail) {
                        if ($value !== $this->name) {
                            $fail('Name does not match.');
                        }
                    }),
            ])
            ->action(function () {
                try {
                    app(IncusClient::class)->deleteInstance($this->target(), $this->name);
                    Notification::make()->title('Instance deleted')->body($this->name)->success()->send();
                    $this->open = false;               // close the panel
                    $this->dispatch('instance-changed'); // table re-pulls; the row disappears
                } catch (\Throwable $e) {
                    Notification::make()->title('Delete failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public string $deleteTarget = '';

    /** Delete snapshot — STRONG guard: type the snapshot name. */
    /** Delete snapshot — STRONG guard: type the snapshot name. */
    public function deleteSnapshotAction(): Action
    {
        return Action::make('deleteSnapshot')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete snapshot')
            ->mountUsing(fn(array $arguments) => $this->deleteTarget = $arguments['snapshot'] ?? '')
            ->schema([
                TextInput::make('confirm')
                    ->label('Type the snapshot name to confirm')
                    ->required()
                    ->rule(fn() => function ($_attribute, $value, $fail) {
                        if ($value !== $this->deleteTarget) {
                            $fail('Name does not match.');
                        }
                    }),
            ])
            ->action(fn() => $this->deleteConfirmed());
    }

    protected function deleteConfirmed(): void
    {
        try {
            app(IncusClient::class)->deleteSnapshot($this->target(), $this->name, $this->deleteTarget);
            Notification::make()->title('Snapshot deleted')->body($this->deleteTarget)->success()->send();
            $this->refreshData();
        } catch (\Throwable $e) {
            Notification::make()->title('Delete failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function render()
    {
        return view('livewire.instance-detail');
    }
}
