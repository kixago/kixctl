<?php

namespace App\Filament\Pages;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ClusterResources extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Resources';

    protected static ?string $title = 'Resources';

    protected string $view = 'filament.pages.cluster-resources';

    public array $clusters = [];

    public array $pools = [];

    public array $volumes = [];

    public array $networks = [];

    public array $profiles = [];

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
                // Resource types this cluster could not serve (scoped cert,
                // version gap, transient failure) — each dims ONE tab's data,
                // never the whole cluster. Live lesson from the nested fixture:
                // a restricted cert may be denied /1.0/storage-pools while
                // serving everything else perfectly.
                'partial' => [],
            ];

            // Reachability = the same bar the Instances page uses: members()
            // answers. If this fails, the cluster is actually down/unreachable.
            try {
                $incus->members($cluster);
            } catch (\Throwable $e) {
                report($e);

                $entry['reachable'] = false;
                $entry['error'] = Str::limit($e->getMessage(), 160);
                $this->clusters[] = $entry;

                continue;
            }

            // Each resource type loads in its own isolation. Compute into
            // locals, merge only what succeeded.
            $pools = $this->tryLoad($entry, 'storage pools (and volumes)',
                fn () => $incus->storagePools($cluster));

            $volumes = [];
            foreach ($pools as $pool) {
                // Per-pool tolerance: one broken pool reports and skips.
                try {
                    $volumes = array_merge(
                        $volumes,
                        $incus->storageVolumes($cluster, $pool['name'])
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $networks = $this->tryLoad($entry, 'networks',
                fn () => $incus->networks($cluster));

            $profiles = $this->tryLoad($entry, 'profiles',
                fn () => $incus->profilesFull($cluster));

            $this->pools = array_merge($this->pools, $pools);
            $this->volumes = array_merge($this->volumes, $volumes);
            $this->networks = array_merge($this->networks, $networks);
            $this->profiles = array_merge($this->profiles, $profiles);

            $this->clusters[] = $entry;
        }

        // Browser event → Alpine re-pulls fresh data (the wire:ignore root is
        // never re-rendered by Livewire; this is the ONLY data path).
        $this->dispatch('resources-changed');
    }

    /**
     * Run one resource-type read in isolation. On failure: report it, record
     * a partial entry on the cluster (for the chip tooltip), return [].
     */
    private function tryLoad(array &$entry, string $what, \Closure $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            $entry['partial'][] = [
                'what' => $what,
                'error' => Str::limit($e->getMessage(), 160),
            ];

            return [];
        }
    }
}
