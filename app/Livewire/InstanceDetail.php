<?php

namespace App\Livewire;

use App\Jobs\StreamInstanceOperation;
use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
    public array $logFiles = [];
    public string $selectedLogFile = '';
    public string $logContent = '';
    public string $consoleContent = '';
    public bool $consoleLoaded = false;
    public string $logView = 'files';
    public string $opToken = '';
    public string $opKind = '';
    public string $opLabel = '';
    public string $deleteTarget = '';

    #[On('open-instance-detail')]
    public function openFor(string $cluster, string $name): void
    {
        $this->cluster = $cluster;
        $this->name = $name;
        $this->open = true;
        $this->logFiles = [];
        $this->selectedLogFile = '';
        $this->logContent = '';
        $this->consoleContent = '';
        $this->consoleLoaded = false;
        $this->logView = 'files';
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

        try {
            $this->logFiles = $incus->instanceLogs($target, $this->name);
        } catch (\Throwable $_e) {
            $this->logFiles = [];
        }
        if ($this->selectedLogFile === '' && ! empty($this->logFiles)) {
            $first = $this->logFiles[0] ?? null;
            $firstName = is_array($first) ? ($first['name'] ?? null) : null;
            if (is_string($firstName) && $firstName !== '') {
                $this->viewLogFile($firstName);
            }
        }
    }

    protected function target()
    {
        return app(ClusterRegistry::class)->find($this->cluster);
    }

    public function showFiles(): void
    {
        $this->logView = 'files';
    }

    public function viewLogFile(string $file): void
    {
        $this->logView = 'files';
        $this->selectedLogFile = $file;
        try {
            $this->logContent = app(IncusClient::class)->instanceLogFile($this->target(), $this->name, $file);
        } catch (\Throwable $e) {
            $this->logContent = '';
            report($e);
            Notification::make()->title(__('instances.notifications.log_load_failed_title'))->body($this->cleanIncusError($e))->danger()->send();
        }
    }

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

    public function refreshLogs(): void
    {
        if ($this->logView === 'console') {
            $this->consoleLoaded = false;
            $this->showConsole();
        } elseif ($this->selectedLogFile !== '') {
            $this->viewLogFile($this->selectedLogFile);
        }
    }

    protected function cleanConsole(string $raw): string
    {
        $s = preg_replace('/\e\][^\x07\e]*(?:\x07|\e\\\\)/', '', $raw);
        $s = preg_replace('/\e[\[\?][0-9;]*[ -\/]*[@-~]/', '', $s);
        $s = preg_replace('/\e[()][0-9A-Za-z]/', '', $s);
        $s = preg_replace('/\e[=>78HMc]/', '', $s);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = preg_replace('/[ \t]+\n/', "\n", $s);
        $s = preg_replace('/\n{3,}/', "\n\n", $s);
        return trim($s);
    }

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

    public function completeOp(): void
    {
        $this->opToken = '';
        $this->opKind = '';
        $this->opLabel = '';
        $this->refreshData();
        $this->dispatch('instance-changed');
    }

    public function dismissOp(): void
    {
        $this->opToken = '';
        $this->opKind = '';
        $this->opLabel = '';
    }

    public function renameInstanceAction(): Action
    {
        return Action::make('renameInstance')
            ->label(__('instances.actions.rename_instance'))
            ->icon('heroicon-o-pencil')
            ->color('gray')
            ->visible(fn (): bool => $this->userCan('instance.rename'))
            ->modalHeading(__('instances.detail.rename.heading'))
            ->fillForm(fn () => ['new_name' => $this->name])
            ->schema([
                TextInput::make('new_name')
                    ->label(__('instances.detail.rename.new_name_label'))
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/')
                    ->validationMessages(['regex' => __('instances.validation.name_regex')]),
            ])
            ->action(function (array $data) {
                if (! $this->userCan('instance.rename')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_rename'))->danger()->send();
                    return;
                }
                if ($data['new_name'] === $this->name) {
                    return;
                }

                try {
                    app(IncusClient::class)->renameInstance($this->target(), $this->name, $data['new_name']);
                    Notification::make()->title(__('instances.notifications.instance_renamed_title'))->success()->send();

                    $this->name = $data['new_name'];
                    $this->refreshData();
                    $this->dispatch('instance-changed');
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('instances.notifications.rename_failed_title'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function editConfigAction(): Action
    {
        return Action::make('editConfig')
            ->label(__('common.actions.edit'))
            ->icon('heroicon-m-cog-8-tooth')
            ->iconButton()
            ->size('sm')
            ->color('gray')
            ->visible(fn (): bool => $this->userCan('instance.config.update'))
            ->modalHeading(__('instances.detail.config.edit_heading'))
            ->fillForm(function () {
                $local = $this->detail['config'] ?? [];
                return [
                    'limits_cpu' => $local['limits.cpu'] ?? '',
                    'limits_memory' => $local['limits.memory'] ?? '',
                    'security_nesting' => ($local['security.nesting'] ?? '') === 'true',
                    'boot_autostart' => ($local['boot.autostart'] ?? '') === 'true',
                ];
            })
            ->schema([
                TextInput::make('limits_cpu')
                    ->label(__('instances.create.cpu_limit_label'))
                    ->helperText(__('instances.detail.config.cpu_helper'))
                    ->placeholder(__('instances.detail.config.cpu_placeholder')),
                TextInput::make('limits_memory')
                    ->label(__('instances.create.memory_limit_label'))
                    ->helperText(__('instances.detail.config.memory_helper'))
                    ->placeholder(__('instances.detail.config.memory_placeholder')),
                Toggle::make('security_nesting')
                    ->label(__('instances.create.security_nesting_label'))
                    ->helperText(__('instances.detail.config.nesting_helper')),
                Toggle::make('boot_autostart')
                    ->label(__('instances.create.boot_autostart_label'))
                    ->helperText(__('instances.detail.config.boot_helper')),
                Toggle::make('restart_now')
                    ->label(__('instances.detail.config.restart_now'))
                    ->helperText(__('instances.detail.config.restart_now_helper'))
                    ->dehydrated(false)
            ])
            ->action(function (array $data) {
                if (! $this->userCan('instance.config.update')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();
                    return;
                }

                $patch = [
                    'config' => [
                        'limits.cpu' => $data['limits_cpu'] ?? '',
                        'limits.memory' => $data['limits_memory'] ?? '',
                        'security.nesting' => $data['security_nesting'] ? 'true' : 'false',
                        'boot.autostart' => $data['boot_autostart'] ? 'true' : 'false',
                    ]
                ];

                try {
                    app(IncusClient::class)->updateInstance($this->target(), $this->name, $patch);
                    Notification::make()->title(__('instances.notifications.config_updated_title'))->success()->send();

                    if ($data['restart_now'] ?? false) {
                        $this->launchOp('restart', '', __('instances.notifications.restarting_to_apply'));
                    } else {
                        $this->refreshData();
                        $this->dispatch('instance-changed');
                    }
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('instances.notifications.update_failed_title'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    /**
     * Profiles on this cluster that are not already attached to this instance,
     * labeled with how widely each is shared (reusing the read-surface signal).
     *
     * @return array<string, string>
     */
    protected function attachableProfileOptions(): array
    {
        $current = data_get($this->config, 'profiles', []);
        $cluster = $this->target();
        if (! $cluster) {
            return [];
        }

        try {
            $all = app(IncusClient::class)->profilesFull($cluster);
        } catch (\Throwable $e) {
            report($e);
            return [];
        }

        return collect($all)
            ->reject(fn (array $p): bool => in_array($p['name'], $current, true))
            ->mapWithKeys(function (array $p): array {
                $label = trans_choice('instances.detail.profiles.option_used_by', $p['used_by'], [
                    'name' => $p['name'],
                    'count' => $p['used_by'],
                ]);

                // Reuse the read surface's "shared widely" signal at the same threshold.
                if ($p['used_by'] >= 10) {
                    $label .= ' · '.__('resources.profiles.shared_widely');
                }

                return [$p['name'] => $label];
            })
            ->all();
    }

    public function attachProfileAction(): Action
    {
        return Action::make('attachProfile')
            ->label(__('instances.detail.profiles.attach_action'))
            ->icon('heroicon-m-plus')
            ->iconButton()
            ->size('sm')
            ->color('gray')
            ->visible(fn (): bool => $this->userCan('profile.attach'))
            ->modalHeading(__('instances.detail.profiles.attach_heading'))
            ->schema([
                Select::make('profile')
                    ->label(__('instances.detail.profiles.attach_label'))
                    ->options(fn (): array => $this->attachableProfileOptions())
                    ->required()
                    ->helperText(__('instances.detail.profiles.attach_helper')),
            ])
            ->action(function (array $data) {
                if (! $this->userCan('profile.attach')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_profile_attach'))->danger()->send();
                    return;
                }

                $profile = $data['profile'] ?? '';
                $current = data_get($this->config, 'profiles', []);
                if ($profile === '' || in_array($profile, $current, true)) {
                    return;
                }

                $next = array_values(array_merge($current, [$profile]));

                try {
                    app(IncusClient::class)->updateInstance($this->target(), $this->name, ['profiles' => $next]);
                    Notification::make()->title(__('instances.notifications.profile_attached_title'))->body($profile)->success()->send();
                    $this->refreshData();
                    $this->dispatch('instance-changed');
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('instances.notifications.update_failed_title'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function detachProfileAction(): Action
    {
        return Action::make('detachProfile')
            ->label(__('instances.detail.profiles.detach_action'))
            ->icon('heroicon-m-x-mark')
            ->iconButton()
            ->size('sm')
            ->color('danger')
            ->visible(fn (): bool => $this->userCan('profile.detach'))
            ->requiresConfirmation()
            ->modalHeading(__('instances.detail.profiles.detach_heading'))
            ->modalDescription(function (array $arguments): string {
                $current = data_get($this->config, 'profiles', []);
                $key = count($current) <= 1
                    ? 'instances.detail.profiles.detach_last_description'
                    : 'instances.detail.profiles.detach_description';

                return __($key, [
                    'profile' => $arguments['profile'] ?? '',
                    'name' => $this->name,
                ]);
            })
            ->action(function (array $arguments) {
                if (! $this->userCan('profile.detach')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_profile_detach'))->danger()->send();
                    return;
                }

                $profile = $arguments['profile'] ?? '';
                $current = data_get($this->config, 'profiles', []);
                if ($profile === '' || ! in_array($profile, $current, true)) {
                    return;
                }

                $next = array_values(array_filter($current, fn ($p): bool => $p !== $profile));

                try {
                    app(IncusClient::class)->updateInstance($this->target(), $this->name, ['profiles' => $next]);
                    Notification::make()->title(__('instances.notifications.profile_detached_title'))->body($profile)->success()->send();
                    $this->refreshData();
                    $this->dispatch('instance-changed');
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('instances.notifications.update_failed_title'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function createSnapshotAction(): Action
    {
        return Action::make('createSnapshot')
            ->label(__('instances.actions.create_snapshot'))
            ->icon('heroicon-o-camera')
            ->color('primary')
            ->visible(fn (): bool => $this->userCan('snapshot.create'))
            ->schema([
                TextInput::make('snapshot')
                    ->label(__('common.labels.name'))
                    ->default(fn () => $this->name.'-'.now()->format('Ymd-His'))
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (array $data) {
                if (! $this->userCan('snapshot.create')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_snapshot_create'))->danger()->send();
                    return;
                }
                $this->launchOp(
                    'create-snapshot',
                    $data['snapshot'],
                    __('instances.notifications.creating_snapshot', ['snapshot' => $data['snapshot']])
                );
            });
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')
            ->label(__('instances.actions.restore_snapshot'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => $this->userCan('snapshot.restore'))
            ->requiresConfirmation()
            ->modalHeading(__('instances.detail.modals.restore_heading'))
            ->modalDescription(fn (array $arguments) => __('instances.detail.modals.restore_description', ['name' => $this->name, 'snapshot' => $arguments['snapshot']]))
            ->action(function (array $arguments) {
                if (! $this->userCan('snapshot.restore')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_snapshot_restore'))->danger()->send();
                    return;
                }
                $this->launchOp(
                    'restore-snapshot',
                    $arguments['snapshot'],
                    __('instances.notifications.restoring_snapshot', ['name' => $this->name, 'snapshot' => $arguments['snapshot']])
                );
            });
    }

    public function deleteInstanceAction(): Action
    {
        return Action::make('deleteInstance')
            ->label(__('instances.actions.delete_instance'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->userCan('instance.delete'))
            ->requiresConfirmation()
            ->modalHeading(__('instances.detail.modals.delete_instance_heading'))
            ->modalDescription(fn () => __('instances.detail.modals.delete_instance_description', ['name' => $this->name]))
            ->action(function () {
                if (! $this->userCan('instance.delete')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_delete'))->danger()->send();
                    return;
                }
                try {
                    app(IncusClient::class)->deleteInstance($this->target(), $this->name);
                    Notification::make()->title(__('instances.notifications.instance_deleted_title'))->body($this->name)->success()->send();
                    $this->open = false;
                    $this->dispatch('instance-changed');
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('instances.notifications.delete_failed_title'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function deleteSnapshotAction(): Action
    {
        return Action::make('deleteSnapshot')
            ->label(__('instances.actions.delete_snapshot'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->userCan('snapshot.delete'))
            ->requiresConfirmation()
            ->modalHeading(__('instances.detail.modals.delete_snapshot_heading'))
            ->mountUsing(fn (array $arguments) => $this->deleteTarget = $arguments['snapshot'] ?? '')
            ->modalDescription(fn () => __('instances.detail.modals.delete_snapshot_description', ['snapshot' => $this->deleteTarget]))
            ->action(fn () => $this->deleteConfirmed());
    }

    protected function deleteConfirmed(): void
    {
        if (! $this->userCan('snapshot.delete')) {
            Notification::make()->title(__('common.notifications.unauthorized_title'))->body(__('instances.notifications.unauthorized_snapshot_delete'))->danger()->send();
            return;
        }

        $this->launchOp(
            'delete-snapshot',
            $this->deleteTarget,
            __('instances.notifications.deleting_snapshot', ['snapshot' => $this->deleteTarget])
        );
    }

    protected function userCan(string $permission): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        return $user?->can($permission) ?? false;
    }

    protected function cleanIncusError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match('/"error"\s*:\s*"([^"]+)"/', $message, $m)) {
            return $m[1];
        }
        return \Illuminate\Support\Str::limit(strtok($message, "\n"), 120);
    }

    public function render()
    {
        return view('livewire.instance-detail');
    }
}
