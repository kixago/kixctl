<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ClusterResources extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected string $view = 'filament.pages.cluster-resources';

    public array $clusters = [];

    public array $pools = [];

    public array $volumes = [];

    public array $networks = [];

    public array $profiles = [];

    // Properties for the delete volume action state
    public string $deleteTargetName = '';

    public string $deleteTargetPool = '';

    public string $deleteTargetCluster = '';

    public static function getNavigationLabel(): string
    {
        return __('resources.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('resources.title');
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $incus = app(IncusClient::class);
        $registry = app(ClusterRegistry::class);

        $this->clusters = [];
        $this->pools = [];
        $this->volumes = [];
        $this->networks = [];
        $this->profiles = [];

        foreach ($registry->all() as $cluster) {
            $entry = [
                'key' => $cluster->key,
                'label' => $cluster->label,
                'reachable' => true,
                'error' => null,
                'version' => null,
                'partial' => [],
            ];

            try {
                $info = $incus->serverInfo($cluster);
                $entry['version'] = $info['server_version'] ?? null;
            } catch (\Throwable $e) {
                report($e);
                $entry['reachable'] = false;
                $entry['error'] = $this->cleanIncusError($e);
                $this->clusters[] = $entry;

                continue;
            }

            $pools = $this->tryLoad($entry, $cluster, 'volumes', ['volumes', 'pools'], fn () => $incus->storagePools($cluster));
            $volumes = [];
            foreach ($pools as $pool) {
                try {
                    $volumes = array_merge($volumes, $incus->storageVolumes($cluster, $pool['name']));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $networks = $this->tryLoad($entry, $cluster, 'networks', ['networks'], fn () => $incus->networks($cluster));
            $profiles = $this->tryLoad($entry, $cluster, 'profiles', ['profiles'], fn () => $incus->profilesFull($cluster));

            $this->pools = array_merge($this->pools, $pools);
            $this->volumes = array_merge($this->volumes, $volumes);
            $this->networks = array_merge($this->networks, $networks);
            $this->profiles = array_merge($this->profiles, $profiles);

            $this->clusters[] = $entry;
        }

        $this->dispatch('resources-changed');
    }

    public function createVolumeAction(): Action
    {
        return Action::make('createVolume')
            ->label(__('resources.volumes.actions.create'))
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->userCan('volume.create'))
            ->schema([
                Select::make('cluster')
                    ->label(__('resources.volumes.create.cluster_label'))
                    ->options(fn () => collect($this->clusters)->where('reachable', true)->pluck('label', 'key'))
                    ->live()
                    ->required(),
                Select::make('pool')
                    ->label(__('resources.volumes.create.pool_label'))
                    ->options(function (Get $get) {
                        if (! $get('cluster')) {
                            return [];
                        }

                        return collect($this->pools)->where('cluster', $get('cluster'))->pluck('name', 'name');
                    })
                    ->required(),
                TextInput::make('name')
                    ->label(__('resources.volumes.create.name_label'))
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/')
                    ->validationMessages(['regex' => __('resources.volumes.create.name_regex')]),
                TextInput::make('description')
                    ->label(__('resources.volumes.create.desc_label'))
                    ->maxLength(255),
            ])
            ->action(function (array $data) {
                if (! $this->userCan('volume.create')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();

                    return;
                }
                $cluster = app(ClusterRegistry::class)->find($data['cluster']);
                if (! $cluster) {
                    return;
                }

                try {
                    app(IncusClient::class)->createStorageVolume($cluster, $data['pool'], $data['name'], $data['description'] ?? null);
                    Notification::make()->title(__('resources.volumes.create.success'))->success()->send();
                    $this->loadData();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.volumes.create.failed'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function deleteVolumeAction(): Action
    {
        return Action::make('deleteVolume')
            ->label(__('resources.volumes.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->userCan('volume.delete'))
            ->requiresConfirmation()
            ->modalHeading(__('resources.volumes.delete.heading'))
            ->mountUsing(function (array $arguments) {
                $this->deleteTargetName = $arguments['name'] ?? '';
                $this->deleteTargetPool = $arguments['pool'] ?? '';
                $this->deleteTargetCluster = $arguments['cluster'] ?? '';
            })
            ->modalDescription(fn () => __('resources.volumes.delete.description', [
                'name' => $this->deleteTargetName,
                'pool' => $this->deleteTargetPool,
            ]))
            ->action(function () {
                if (! $this->userCan('volume.delete')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();

                    return;
                }
                $cluster = app(ClusterRegistry::class)->find($this->deleteTargetCluster);
                if (! $cluster) {
                    return;
                }

                try {
                    app(IncusClient::class)->deleteStorageVolume($cluster, $this->deleteTargetPool, $this->deleteTargetName);
                    Notification::make()->title(__('resources.volumes.delete.success'))->success()->send();
                    $this->loadData();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.volumes.delete.failed'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function createNetworkAction(): Action
    {
        return Action::make('createNetwork')
            ->label(__('resources.networks.actions.create'))
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->userCan('network.create'))
            ->modalHeading(__('resources.networks.actions.create'))
            ->modalDescription(__('resources.networks.create.helper'))
            ->schema([
                Select::make('cluster')
                    ->label(__('resources.networks.create.cluster_label'))
                    ->options(fn () => collect($this->clusters)->where('reachable', true)->pluck('label', 'key'))
                    ->required(),
                TextInput::make('name')
                    ->label(__('resources.networks.create.name_label'))
                    ->required()
                    ->maxLength(15)
                    ->regex('/^[a-zA-Z][a-zA-Z0-9-]{0,14}$/')
                    ->validationMessages(['regex' => __('resources.networks.create.name_regex')]),
                TextInput::make('description')
                    ->label(__('resources.networks.create.desc_label'))
                    ->maxLength(255),
            ])
            ->action(function (array $data) {
                if (! $this->userCan('network.create')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();

                    return;
                }
                $cluster = app(ClusterRegistry::class)->find($data['cluster']);
                if (! $cluster) {
                    return;
                }

                try {
                    app(IncusClient::class)->createNetwork($cluster, $data['name'], 'bridge', [], $data['description'] ?? null);
                    Notification::make()->title(__('resources.networks.create.success'))->success()->send();
                    $this->loadData();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.networks.create.failed'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function editNetworkAction(): Action
    {
        return Action::make('editNetwork')
            ->label(__('resources.networks.actions.edit'))
            ->icon('heroicon-o-pencil')
            ->color('gray')
            ->visible(fn (): bool => $this->userCan('network.update'))
            ->modalHeading(__('resources.networks.edit.heading'))
            ->fillForm(function (array $arguments): array {
                $cluster = app(ClusterRegistry::class)->find($arguments['cluster'] ?? '');
                if (! $cluster) {
                    return [];
                }

                try {
                    $net = app(IncusClient::class)->network($cluster, $arguments['name'] ?? '');
                } catch (\Throwable $e) {
                    report($e);

                    return [];
                }

                return [
                    'description' => $net['description'] ?? '',
                    'ipv4_nat' => ($net['config']['ipv4.nat'] ?? '') === 'true',
                    'ipv6_nat' => ($net['config']['ipv6.nat'] ?? '') === 'true',
                ];
            })
            ->schema([
                TextInput::make('description')
                    ->label(__('resources.networks.edit.desc_label'))
                    ->maxLength(255),
                Toggle::make('ipv4_nat')
                    ->label(__('resources.networks.edit.ipv4_nat_label'))
                    ->helperText(__('resources.networks.edit.nat_helper')),
                Toggle::make('ipv6_nat')
                    ->label(__('resources.networks.edit.ipv6_nat_label')),
            ])
            ->action(function (array $data, array $arguments) {
                if (! $this->userCan('network.update')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();

                    return;
                }
                $cluster = app(ClusterRegistry::class)->find($arguments['cluster'] ?? '');
                if (! $cluster) {
                    return;
                }
                $name = $arguments['name'] ?? '';

                // Managed-only gate, verified against ground truth rather than the
                // client-supplied row: re-read the network and refuse if it is
                // observed (host-created), with a plain-language notice.
                try {
                    $net = app(IncusClient::class)->network($cluster, $name);
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.networks.edit.failed'))->body($this->cleanIncusError($e))->danger()->send();

                    return;
                }
                if (! ($net['managed'] ?? false)) {
                    Notification::make()->title(__('resources.networks.edit.failed'))->body(__('resources.networks.unmanaged_refused', ['name' => $name]))->danger()->send();

                    return;
                }

                try {
                    app(IncusClient::class)->updateNetwork($cluster, $name, [
                        'ipv4.nat' => $data['ipv4_nat'] ? 'true' : 'false',
                        'ipv6.nat' => $data['ipv6_nat'] ? 'true' : 'false',
                    ], $data['description'] ?? '');
                    Notification::make()->title(__('resources.networks.edit.success'))->success()->send();
                    $this->loadData();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.networks.edit.failed'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function deleteNetworkAction(): Action
    {
        return Action::make('deleteNetwork')
            ->label(__('resources.networks.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->userCan('network.delete'))
            ->requiresConfirmation()
            ->modalHeading(__('resources.networks.delete.heading'))
            ->modalDescription(fn (array $arguments) => __('resources.networks.delete.description', [
                'name' => $arguments['name'] ?? '',
                'cluster' => $arguments['cluster_label'] ?? ($arguments['cluster'] ?? ''),
            ]))
            ->action(function (array $arguments) {
                if (! $this->userCan('network.delete')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();

                    return;
                }
                $cluster = app(ClusterRegistry::class)->find($arguments['cluster'] ?? '');
                if (! $cluster) {
                    return;
                }
                $name = $arguments['name'] ?? '';

                try {
                    $net = app(IncusClient::class)->network($cluster, $name);
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.networks.delete.failed'))->body($this->cleanIncusError($e))->danger()->send();

                    return;
                }
                if (! ($net['managed'] ?? false)) {
                    Notification::make()->title(__('resources.networks.delete.failed'))->body(__('resources.networks.unmanaged_refused', ['name' => $name]))->danger()->send();

                    return;
                }

                try {
                    app(IncusClient::class)->deleteNetwork($cluster, $name);
                    Notification::make()->title(__('resources.networks.delete.success'))->success()->send();
                    $this->loadData();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.networks.delete.failed'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    public function editProfileAction(): Action
    {
        return Action::make('editProfile')
            ->label(__('resources.profiles.actions.edit'))
            ->icon('heroicon-o-pencil')
            ->color('gray')
            ->visible(fn (): bool => $this->userCan('profile.update'))
            ->modalHeading(__('resources.profiles.edit.heading'))
            ->modalDescription(fn (array $arguments) => trans_choice(
                'resources.profiles.edit.affects',
                (int) ($arguments['used_by'] ?? 0),
                ['count' => (int) ($arguments['used_by'] ?? 0)]
            ).' '.__('resources.profiles.edit.confirm'))
            ->modalSubmitActionLabel(__('common.actions.save'))
            ->fillForm(function (array $arguments): array {
                $cluster = app(ClusterRegistry::class)->find($arguments['cluster'] ?? '');
                if (! $cluster) {
                    return [];
                }

                try {
                    $p = app(IncusClient::class)->profile($cluster, $arguments['name'] ?? '');
                } catch (\Throwable $e) {
                    report($e);

                    return [];
                }

                $config = $p['config'] ?? [];

                return [
                    'description' => $p['description'] ?? '',
                    'limits_cpu' => $config['limits.cpu'] ?? '',
                    'limits_memory' => $config['limits.memory'] ?? '',
                    'security_nesting' => ($config['security.nesting'] ?? '') === 'true',
                    'boot_autostart' => ($config['boot.autostart'] ?? '') === 'true',
                ];
            })
            ->schema([
                TextInput::make('description')
                    ->label(__('resources.profiles.edit.desc_label'))
                    ->maxLength(255),
                TextInput::make('limits_cpu')
                    ->label(__('resources.profiles.edit.cpu_label'))
                    ->helperText(__('resources.profiles.edit.cpu_helper'))
                    ->placeholder(__('resources.profiles.edit.cpu_placeholder')),
                TextInput::make('limits_memory')
                    ->label(__('resources.profiles.edit.memory_label'))
                    ->helperText(__('resources.profiles.edit.memory_helper'))
                    ->placeholder(__('resources.profiles.edit.memory_placeholder')),
                Toggle::make('security_nesting')
                    ->label(__('resources.profiles.edit.nesting_label'))
                    ->helperText(__('resources.profiles.edit.nesting_helper')),
                Toggle::make('boot_autostart')
                    ->label(__('resources.profiles.edit.autostart_label'))
                    ->helperText(__('resources.profiles.edit.autostart_helper')),
            ])
            ->action(function (array $data, array $arguments) {
                if (! $this->userCan('profile.update')) {
                    Notification::make()->title(__('common.notifications.unauthorized_title'))->danger()->send();

                    return;
                }
                $cluster = app(ClusterRegistry::class)->find($arguments['cluster'] ?? '');
                if (! $cluster) {
                    return;
                }
                $name = $arguments['name'] ?? '';

                // Only the curated keys are sent; PATCH merges them and Incus keeps
                // every other config key and every device on the profile untouched.
                $config = [
                    'limits.cpu' => $data['limits_cpu'] ?? '',
                    'limits.memory' => $data['limits_memory'] ?? '',
                    'security.nesting' => ($data['security_nesting'] ?? false) ? 'true' : 'false',
                    'boot.autostart' => ($data['boot_autostart'] ?? false) ? 'true' : 'false',
                ];

                try {
                    app(IncusClient::class)->updateProfile($cluster, $name, $config, $data['description'] ?? '');
                    Notification::make()->title(__('resources.profiles.edit.success'))->success()->send();
                    $this->loadData();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->title(__('resources.profiles.edit.failed'))->body($this->cleanIncusError($e))->danger()->send();
                }
            });
    }

    protected function userCan(string $permission): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->can($permission) ?? false;
    }

    private function tryLoad(array &$entry, Cluster $cluster, string $whatKey, array $tabs, \Closure $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            $reason = $this->cleanIncusError($e);
            $entry['partial'][] = [
                'what' => $whatKey,
                'tabs' => $tabs,
                'summary' => __("resources.notice.summary_{$whatKey}"),
                'detail' => $this->noticeDetail($reason, $entry['version'], $cluster),
            ];

            return [];
        }
    }

    private function cleanIncusError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match('/"error"\s*:\s*"([^"]+)"/', $message, $m)) {
            return $m[1];
        }

        return Str::limit(strtok($message, "\n"), 120);
    }

    private function noticeDetail(string $reason, ?string $version, Cluster $cluster): string
    {
        $fp = $this->certFingerprint($cluster);

        if (stripos($reason, 'restricted') === false) {
            return __('resources.notice.declined_reason', ['reason' => lcfirst($reason)]);
        }

        return __('resources.notice.restricted_cert_cause', [
            'reason' => lcfirst($reason),
            'version' => $version ?? __('common.labels.unknown_version'),
            'fingerprint' => $fp ? " (fingerprint {$fp})" : '',
        ]);
    }

    private function certFingerprint(Cluster $cluster): ?string
    {
        $pem = $cluster->connection['client_cert'] ?? null;
        if (! is_string($pem) || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }
        try {
            $fp = openssl_x509_fingerprint($pem, 'sha256');

            return $fp ? substr($fp, 0, 12).'…' : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
