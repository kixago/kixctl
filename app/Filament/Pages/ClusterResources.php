<?php

namespace App\Filament\Pages;

use App\Services\Incus\Cluster;
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
                'version' => null,
                // Resource types this cluster could not serve. Each dims ONE
                // tab, never the whole cluster (degradation is per-capability).
                // Each entry: what / tabs / error / hint (remediation text).
                'partial' => [],
            ];

            // Reachability = the same bar the Instances page uses. serverInfo()
            // reads /1.0, which even restricted certs may read — so this also
            // captures the Incus version for remediation messaging.
            try {
                $info = $incus->serverInfo($cluster);
                $entry['version'] = $info['server_version'] ?? null;
            } catch (\Throwable $e) {
                report($e);

                $entry['reachable'] = false;
                $entry['error'] = Str::limit($e->getMessage(), 160);
                $this->clusters[] = $entry;

                continue;
            }

            // Each resource type loads in its own isolation. Compute into
            // locals, merge only what succeeded.
            $pools = $this->tryLoad($entry, $cluster, 'storage pools (and volumes)', ['volumes', 'pools'],
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

            $networks = $this->tryLoad($entry, $cluster, 'networks', ['networks'],
                fn () => $incus->networks($cluster));

            $profiles = $this->tryLoad($entry, $cluster, 'profiles', ['profiles'],
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
     * Run one resource-type read in isolation. On failure: report it, record a
     * partial entry (tab list + diagnosis + remediation) on the cluster, return [].
     */
    private function tryLoad(array &$entry, Cluster $cluster, string $what, array $tabs, \Closure $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            $entry['partial'][] = [
                'what' => $what,
                'tabs' => $tabs,
                'error' => Str::limit($e->getMessage(), 160),
                'hint' => $this->remediationHint($e->getMessage(), $entry['version'], $cluster),
            ];

            return [];
        }
    }

    /**
     * Turn a known failure shape into an actionable diagnosis. A restricted
     * cert CANNOT escalate its own scope via the API (an Incus security
     * invariant we rely on and advertise) — so remediation is necessarily an
     * action by the target cluster's admin; our job is to make it exact.
     */
    private function remediationHint(string $error, ?string $version, Cluster $cluster): ?string
    {
        if (stripos($error, 'restricted') === false) {
            return null;
        }

        $fp = $this->certFingerprint($cluster);

        return 'This server (Incus '.($version ?? 'unknown').') denies this read to restricted certificates.'
            .' Fix on the cluster itself, at its admin\'s discretion: upgrade Incus (7.x serves restricted'
            .' certificates a filtered view), or grant the kixctl certificate'
            .($fp ? ' ('.$fp.')' : '')
            .' full access via its trust entry — full access lets kixctl manage everything on that server;'
            .' review before granting. kixctl deliberately cannot change its own access.';
    }

    /** SHA-256 fingerprint (Incus trust-store identity) of our stored client cert. */
    private function certFingerprint(Cluster $cluster): ?string
    {
        $pem = $cluster->connection['client_cert'] ?? null;

        if (! is_string($pem) || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }

        try {
            $fp = openssl_x509_fingerprint($pem, 'sha256');

            return $fp ? 'fingerprint '.substr($fp, 0, 12).'…' : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
