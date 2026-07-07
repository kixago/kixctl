<?php

namespace App\Filament\Widgets;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class ClusterOverview extends Widget
{
    protected string $view = 'filament.widgets.cluster-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    // Live refresh. Bump to '60s' or set null to disable.
    protected static ?string $pollingInterval = '30s';

    protected function getViewData(): array
    {
        /** @var ClusterRegistry $registry */
        $registry = app(ClusterRegistry::class);
        /** @var IncusClient $client */
        $client = app(IncusClient::class);

        $instances = [];
        $nodes = [];
        $labels = [];
        $errors = [];

        // NOTE: assumes ClusterRegistry::all() returns an iterable of Cluster.
        // If yours exposes clusters()/each() instead, change ONLY this line.
        foreach ($registry->all() as $cluster) {
            $labels[] = $cluster->label;
            try {
                foreach ($client->members($cluster) as $m) {
                    // Fetch live resource state per node; tolerate individual failures.
                    try {
                        $m['state'] = $client->memberState($cluster, $m['name']);
                    } catch (\Throwable $_e) {
                        $m['state'] = null;
                    }
                    $nodes[] = $m;
                }
                foreach ($client->instances($cluster) as $i) {
                    $instances[] = $i;
                }
            } catch (\Throwable $e) {
                $errors[] = $cluster->label;
            }
        }

        $all = collect($instances);
        $total = $all->count();
        $running = $all->where('status', 'Running')->count();
        $stopped = $all->where('status', 'Stopped')->count();
        $other = max(0, $total - $running - $stopped);
        $containers = $all->where('type', 'container')->count();
        $vms = $all->where('type', 'virtual-machine')->count();

        $nodeCol = collect($nodes);
        $nodesTotal = $nodeCol->count();
        $nodesOnline = $nodeCol->filter(fn($m) => strtolower($m['status']) === 'online')->count();

        $byNode = $all->groupBy('node');

        $roleMap = [
            'database'         => 'DB',
            'database-leader'  => 'DB leader',
            'database-standby' => 'DB standby',
            'event-hub'        => 'events',
            'ovn-chassis'      => 'OVN',
        ];

        $nodeCards = $nodeCol->map(function ($m) use ($byNode, $roleMap) {
            /** @var Collection $list */
            $list = $byNode->get($m['name'], collect());
            $t = $list->count();
            $run = $list->where('status', 'Running')->count();
            $stop = $list->where('status', 'Stopped')->count();

            return [
                'name'       => $m['name'],
                'state'      => $m['state'] ?? null,
                'online'     => strtolower($m['status']) === 'online',
                'status'     => $m['status'],
                'roleLabels' => collect($m['roles'] ?? [])
                    ->map(fn($r) => $roleMap[$r] ?? $r)
                    ->all(),
                'total'      => $t,
                'running'    => $run,
                'stopped'    => $stop,
                'other'      => max(0, $t - $run - $stop),
                'containers' => $list->where('type', 'container')->count(),
                'vms'        => $list->where('type', 'virtual-machine')->count(),
            ];
        })->sortByDesc('total')->values()->all();

        $pct = fn(int $n) => $total > 0 ? round($n / $total * 100, 1) : 0;

        return [
            'total'       => $total,
            'running'     => $running,
            'stopped'     => $stopped,
            'other'       => $other,
            'containers'  => $containers,
            'vms'         => $vms,
            'runPct'      => $pct($running),
            'stopPct'     => $pct($stopped),
            'otherPct'    => $pct($other),
            'nodesTotal'  => $nodesTotal,
            'nodesOnline' => $nodesOnline,
            'nodeCards'   => $nodeCards,
            'label'       => count($labels) === 1 ? $labels[0] : count($labels) . ' clusters',
            'errors'      => $errors,
            'generatedAt' => now()->format('H:i:s'),
        ];
    }
}
