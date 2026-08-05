<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\AppRoute;
use App\Models\Repository;
use App\Models\ProfileRestartFlag;
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
                $this->instances = array_merge($this->instances, $this->flagRestarts($instances, $cluster->key));

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

        $this->instances = $this->annotateRevisions($this->instances);

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

    /**
     * Annotate instances with an advisory restart warning. An instance is flagged
     * only when it is running, inherits a profile whose definition changed in a way
     * that needs a restart, is a type the change affects, and has not been restarted
     * since (its last_used_at predates the flag). Restarting it — through kixctl or
     * anywhere else — moves last_used_at past flagged_at and clears the warning on
     * the next load, so nothing goes stale and nothing nags after the work is done.
     */
    protected function flagRestarts(array $instances, string $clusterKey): array
    {
        $flags = ProfileRestartFlag::forCluster($clusterKey);
        if ($flags->isEmpty()) {
            return $instances;
        }

        return array_map(function (array $instance) use ($flags) {
            $instance['needs_restart'] = false;
            $instance['restart_reason'] = null;

            if (($instance['status'] ?? '') !== 'Running') {
                return $instance;
            }

            $lastUsed = null;
            $ts = $instance['last_used_at'] ?? null;
            if ($ts) {
                try {
                    $parsed = \Illuminate\Support\Carbon::parse($ts);
                    $lastUsed = $parsed->year > 1 ? $parsed : null;
                } catch (\Throwable) {
                    $lastUsed = null;
                }
            }

            foreach ($flags as $flag) {
                if (! in_array($flag->profile_name, $instance['profiles'] ?? [], true)) {
                    continue;
                }
                if (! in_array($instance['type'] ?? '', $flag->affected_types ?? [], true)) {
                    continue;
                }
                if ($lastUsed !== null && $lastUsed->greaterThanOrEqualTo($flag->flagged_at)) {
                    continue;
                }

                $instance['needs_restart'] = true;
                $instance['restart_reason'] = $this->restartReason($flag);
                break;
            }

            return $instance;
        }, $instances);
    }

    protected function restartReason(ProfileRestartFlag $flag): string
    {
        $phrases = [];
        foreach ($flag->changes ?? [] as $change) {
            $key = $change['key'] ?? '';
            $to = $change['to'] ?? '';
            $phrases[] = match ($key) {
                'security.nesting' => $to === 'true'
                    ? __('instances.restart.change.nesting_on')
                    : __('instances.restart.change.nesting_off'),
                'limits.cpu' => __('instances.restart.change.cpu'),
                'limits.memory' => __('instances.restart.change.memory'),
                default => $key,
            };
        }

        return __('instances.restart.reason', [
            'profile' => $flag->profile_name,
            'what' => implode(', ', $phrases),
        ]);
    }

    /**
     * Tag each instance with its deploy-revision role so the view can group
     * superseded revisions under their live one:
     *   app     — the owning app slug when the name is <slug>-<sha7> for a
     *             REGISTERED repo, else null (a hand-created instance never
     *             matches, so it is never grouped or altered).
     *   retired — the kixctl retirement marker is set. Only ever present on a
     *             superseded revision, so this cannot catch a hand-made box.
     *   is_live — this exact instance is the app's currently-routed revision.
     * The live revision anchors the group; retired ones collapse beneath it;
     * a landed-but-unpromoted revision is neither retired nor live, so it stays
     * a normal top-level row.
     */
    protected function annotateRevisions(array $instances): array
    {
        $slugs = Repository::query()->pluck('slug')->all();
        $live = AppRoute::query()->pluck('live_instance', 'app')->all();

        return array_map(function (array $i) use ($slugs, $live) {
            $name = (string) ($i['name'] ?? '');

            $app = preg_match('/^(.+)-[0-9a-f]{7}$/', $name, $m) && in_array($m[1], $slugs, true)
                ? $m[1]
                : null;

            $i['app'] = $app;
            $i['retired'] = ! empty($i['retired_at']);
            $i['is_live'] = $app !== null && ($live[$app] ?? null) === $name;

            return $i;
        }, $instances);
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
