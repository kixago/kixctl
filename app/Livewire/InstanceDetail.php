<?php

namespace App\Livewire;

use App\Jobs\StreamInstanceOperation;
use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

    // --- Logs (P2-A) ---
    public array $logFiles = [];

    public string $selectedLogFile = '';

    public string $logContent = '';

    public string $consoleContent = '';

    public bool $consoleLoaded = false;

    public string $logView = 'files'; // 'files' | 'console'

    // --- Streaming snapshot-op state (P2-C tail) ---
    public string $opToken = '';

    public string $opKind = '';   // 'create-snapshot' | 'restore-snapshot' | 'delete-snapshot'

    public string $opLabel = '';  // human heading shown while the op runs

    #[On('open-instance-detail')]
    public function openFor(string $cluster, string $name): void
    {
        $this->cluster = $cluster;
        $this->name = $name;
        $this->open = true;

        // Reset log state for the newly opened instance.
        $this->logFiles = [];
        $this->selectedLogFile = '';
        $this->logContent = '';
        $this->consoleContent = '';
        $this->consoleLoaded = false;
        $this->logView = 'files';

        // Clear any stale streaming-op state from a previous instance.
        $this->opToken = '';
        $this->opKind = '';
        $this->opLabel = '';

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

        // Log file list is cheap; load it and auto-open the first file so the
        // Logs → Files pane isn't empty on open. File contents are capped in the client.
        try {
            $this->logFiles = $incus->instanceLogs($target, $this->name);
        } catch (\Throwable $_e) {
            $this->logFiles = [];
        }
        if ($this->selectedLogFile === '' && ! empty($this->logFiles)) {
            $this->viewLogFile($this->logFiles[0]['name']);
        }
    }

    protected function target()
    {
        return app(ClusterRegistry::class)->find($this->cluster);
    }

    // --- Logs: tab switching + content loading (read-only; no permission gate) ---

    /** Switch to the Files tab. */
    public function showFiles(): void
    {
        $this->logView = 'files';
    }

    /** Load and show one log file's contents. */
    public function viewLogFile(string $file): void
    {
        $this->logView = 'files';
        $this->selectedLogFile = $file;
        try {
            $this->logContent = app(IncusClient::class)
                ->instanceLogFile($this->target(), $this->name, $file);
        } catch (\Throwable $e) {
            $this->logContent = '';
            Notification::make()->title('Could not load log')->body($e->getMessage())->danger()->send();
        }
    }

    /** Switch to the Console tab, lazily loading the cleaned buffer once. */
    public function showConsole(): void
    {
        $this->logView = 'console';
        if ($this->consoleLoaded) {
            return;
        }
        try {
            $raw = app(IncusClient::class)->consoleLog($this->target(), $this->name);
            $this->consoleContent = $this->cleanConsole($raw);
        } catch (\Throwable $_e) {
            $this->consoleContent = '';
        }
        $this->consoleLoaded = true;
    }

    /** Re-pull whichever log surface is currently in view. */
    public function refreshLogs(): void
    {
        if ($this->logView === 'console') {
            $this->consoleLoaded = false;
            $this->showConsole();
        } elseif ($this->selectedLogFile !== '') {
            $this->viewLogFile($this->selectedLogFile);
        }
    }

    /**
     * Strip ANSI/VT escape sequences and control noise from a console buffer,
     * leaving readable text. VGA boot menus (cursor-positioned) come out sparse
     * — that's inherent — but serial/kernel output comes out clean.
     */
    protected function cleanConsole(string $raw): string
    {
        // Strip escape sequences: OSC (…BEL/ST), CSI (…final byte),
        // charset-selects, and misc single-char ESC codes.
        $s = preg_replace('/\e\][^\x07\e]*(?:\x07|\e\\\\)/', '', $raw);
        $s = preg_replace('/\e[\[\?][0-9;]*[ -\/]*[@-~]/', '', $s);
        $s = preg_replace('/\e[()][0-9A-Za-z]/', '', $s);
        $s = preg_replace('/\e[=>78HMc]/', '', $s);

        // Normalize newlines, then drop remaining control chars (keep tab + newline).
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);

        // Tidy: trailing spaces and long runs of blank lines.
        $s = preg_replace('/[ \t]+\n/', "\n", $s);
        $s = preg_replace('/\n{3,}/', "\n\n", $s);

        return trim($s);
    }

    /**
     * Hand a snapshot op to the Horizon worker and flip the slide-over into its
     * live-progress state. The worker broadcasts on instance-op.{token}; the
     * Alpine island in the blade renders it. The permission re-check and any
     * type-the-name guard live in the calling action, NOT here.
     */
    protected function launchOp(string $op, string $snapshotName, string $label): void
    {
        $token = (string) Str::random(24);
        $this->opToken = $token;
        $this->opKind = $op;
        $this->opLabel = $label;

        StreamInstanceOperation::dispatch(
            $token,
            $this->cluster,
            $op,
            $this->name,
            $snapshotName,
            Auth::id(),
        );
    }

    /** Island calls this on a `done` broadcast: refresh live state, fan out, clear. */
    public function completeOp(): void
    {
        $this->opToken = '';
        $this->opKind = '';
        $this->opLabel = '';
        $this->refreshData();
        $this->dispatch('instance-changed'); // tell the fleet table to reload
    }

    /** Island calls this to dismiss a terminal (failed) op without refreshing. */
    public function dismissOp(): void
    {
        $this->opToken = '';
        $this->opKind = '';
        $this->opLabel = '';
    }

    /** Create snapshot — soft confirm (non-destructive). Streams via the worker. */
    public function createSnapshotAction(): Action
    {
        return Action::make('createSnapshot')
            ->label('New snapshot')
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->visible(fn (): bool => $this->userCan('snapshot.create'))
            ->schema([
                TextInput::make('snapshot')
                    ->label('Snapshot name')
                    ->default(fn () => $this->name.'-'.now()->format('Ymd-His'))
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (array $data) {
                if (! $this->userCan('snapshot.create')) {
                    Notification::make()->title('Not authorized')->body('You do not have permission to create snapshots.')->danger()->send();

                    return;
                }

                $this->launchOp(
                    'create-snapshot',
                    $data['snapshot'],
                    'Creating snapshot “'.$data['snapshot'].'”…',
                );
            });
    }

    /** Restore — STRONG guard: type the instance name. Destructive. */
    public function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => $this->userCan('snapshot.restore'))
            ->requiresConfirmation()
            ->modalHeading('Restore snapshot')
            ->modalDescription(fn (array $arguments) => "This reverts “{$this->name}” entirely to snapshot “{$arguments['snapshot']}”, including its data. This cannot be undone.")
            ->schema([
                TextInput::make('confirm')
                    ->label("Type the instance name (“{$this->name}”) to confirm")
                    ->required()
                    ->rule(fn () => function ($_attr, $value, $fail) {
                        if ($value !== $this->name) {
                            $fail('Name does not match.');
                        }
                    }),
            ])
            ->action(function (array $arguments) {
                if (! $this->userCan('snapshot.restore')) {
                    Notification::make()->title('Not authorized')->body('You do not have permission to restore snapshots.')->danger()->send();

                    return;
                }
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
            ->visible(fn (): bool => $this->userCan('instance.delete'))
            ->requiresConfirmation()
            ->modalHeading('Delete instance')
            ->modalDescription(fn () => "This permanently deletes “{$this->name}” and its root filesystem. Attached volumes that persist are not removed. This cannot be undone.")
            ->schema([
                TextInput::make('confirm')
                    ->label("Type the instance name (“{$this->name}”) to confirm")
                    ->required()
                    ->rule(fn () => function ($_attr, $value, $fail) {
                        if ($value !== $this->name) {
                            $fail('Name does not match.');
                        }
                    }),
            ])
            ->action(function () {
                if (! $this->userCan('instance.delete')) {
                    Notification::make()->title('Not authorized')->body('You do not have permission to delete instances.')->danger()->send();

                    return;
                }
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
    public function deleteSnapshotAction(): Action
    {
        return Action::make('deleteSnapshot')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->userCan('snapshot.delete'))
            ->requiresConfirmation()
            ->modalHeading('Delete snapshot')
            ->mountUsing(fn (array $arguments) => $this->deleteTarget = $arguments['snapshot'] ?? '')
            ->schema([
                TextInput::make('confirm')
                    ->label('Type the snapshot name to confirm')
                    ->required()
                    ->rule(fn () => function ($_attribute, $value, $fail) {
                        if ($value !== $this->deleteTarget) {
                            $fail('Name does not match.');
                        }
                    }),
            ])
            ->action(fn () => $this->deleteConfirmed());
    }

    protected function deleteConfirmed(): void
    {
        if (! $this->userCan('snapshot.delete')) {
            Notification::make()->title('Not authorized')->body('You do not have permission to delete snapshots.')->danger()->send();

            return;
        }

        $this->launchOp(
            'delete-snapshot',
            $this->deleteTarget,
            'Deleting snapshot “'.$this->deleteTarget.'”…',
        );
    }

    /**
     * Whether the current user holds a given permission.
     * super_admin bypasses via Shield's gate interception.
     */
    protected function userCan(string $permission): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->can($permission) ?? false;
    }

    public function render()
    {
        return view('livewire.instance-detail');
    }
}
