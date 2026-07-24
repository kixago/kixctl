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
