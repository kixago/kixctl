<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class ClusterInstances extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected string $view = 'filament.pages.cluster-instances';

    public array $clusters = [];
    public array $members = [];
    public array $instances = [];

    public static function getNavigationLabel(): string
    {
        return __('instances.plural');
    }

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return __('instances.title');
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
        $this->members = [];
        $this->instances = [];

        foreach ($registry->all() as $cluster) {
            try {
                $members = $incus->members($cluster);
                $instances = $incus->instances($cluster);

                $this->members = array_merge($this->members, $members);
                $this->instances = array_merge($this->instances, $instances);

                $this->clusters[] = [
                    'key' => $cluster->key,
                    'label' => $cluster->label,
                    'reachable' => true,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                report($e);
                $this->clusters[] = [
                    'key' => $cluster->key,
                    'label' => $cluster->label,
                    'reachable' => false,
                    'error' => $this->cleanIncusError($e),
                ];
            }
        }

        $this->members = collect($this->members)->map(function ($m) {
            $parts = parse_url($m['url']);
            return [
                ...$m,
                'host' => $parts['host'] ?? $m['url'],
                'port' => isset($parts['port']) ? (string) $parts['port'] : '',
                'count' => collect($this->instances)
                    ->where('cluster', $m['cluster'])
                    ->where('node', $m['name'])
                    ->count(),
            ];
        })->values()->all();

        $this->dispatch('instance-changed');
    }

    protected function userCan(string $permission): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        return $user?->can($permission) ?? false;
    }

    public function canRun(string $action): bool
    {
        return $this->userCan('instance.'.$action);
    }

    public function runAction(string $cluster, string $name, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            Notification::make()->title(__('common.notifications.unsupported_action'))->danger()->send();
            return;
        }

        if (! $this->userCan('instance.'.$action)) {
            Notification::make()
                ->title(__('common.notifications.unauthorized_title'))
                ->body(__('instances.notifications.unauthorized_action', ['action' => $action]))
                ->danger()
                ->send();
            return;
        }

        $target = app(ClusterRegistry::class)->find($cluster);
        if (! $target) {
            Notification::make()->title(__('clusters.notifications.unknown_cluster'))->danger()->send();
            return;
        }

        try {
            app(IncusClient::class)->setInstanceState($target, $name, $action);
            Notification::make()
                ->title(__('instances.notifications.action_succeeded_title', ['action' => ucfirst($action)]))
                ->body($name)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title(__('instances.notifications.action_failed_title', ['action' => ucfirst($action)]))
                ->body($this->cleanIncusError($e))
                ->danger()
                ->send();
        }

        $this->loadData();
    }

    #[On('instance-created')]
    public function refreshInstances(): void
    {
        $this->loadData();
    }

    protected function cleanIncusError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match('/"error"\s*:\s*"([^"]+)"/', $message, $m)) {
            return $m[1];
        }
        return \Illuminate\Support\Str::limit(strtok($message, "\n"), 120);
    }
}
