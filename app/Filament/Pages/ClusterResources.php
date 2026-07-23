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
                // Resource types this cluster could not serve. Each affects its
                // own tab; a denied read never marks the whole cluster down.
                // Entries carry: what / tabs / summary (one line) / detail
                // (expanded explanation).
                'partial' => [],
            ];

            // Reachability check, same bar as the Instances page. Also records
            // the server version; /1.0 is readable under a restricted
            // certificate, so this works for every cluster we can reach at all.
            try {
                $info = $incus->serverInfo($cluster);
                $entry['version'] = $info['server_version'] ?? null;
            } catch (\Throwable $e) {
                report($e);

                $entry['reachable'] = false;
                $entry['error'] = $this->cleanIncusError($e->getMessage());
                $this->clusters[] = $entry;

                continue;
            }

            // Each resource type loads independently. Results are computed into
            // locals and merged only on success, so a failure leaves no
            // half-loaded state.
            $pools = $this->tryLoad($entry, $cluster, 'storage pools and volumes', ['volumes', 'pools'],
                fn () => $incus->storagePools($cluster));

            $volumes = [];
            foreach ($pools as $pool) {
                // One broken pool is logged and skipped; the rest still load.
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

        // Browser event; Alpine pulls the fresh data. The wire:ignore root is
        // never re-rendered by Livewire, so this event is the only data path.
        $this->dispatch('resources-changed');
    }

    /**
     * Run one resource-type read in isolation. On failure the error is logged,
     * a notice is recorded against the cluster for the affected tabs, and an
     * empty list is returned so the rest of the page loads normally.
     */
    private function tryLoad(array &$entry, Cluster $cluster, string $what, array $tabs, \Closure $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            $reason = $this->cleanIncusError($e->getMessage());

            $entry['partial'][] = [
                'what' => $what,
                'tabs' => $tabs,
                'summary' => ucfirst($what).' are not shown for this cluster.',
                'detail' => $this->noticeDetail($reason, $entry['version'], $cluster),
            ];

            return [];
        }
    }

    /**
     * Reduce an HTTP client exception to the server's own error message. Incus
     * returns a JSON body with an "error" field; the surrounding status line
     * and JSON belong in the log, not on the page.
     */
    private function cleanIncusError(string $message): string
    {
        if (preg_match('/"error"\s*:\s*"([^"]+)"/', $message, $m)) {
            return $m[1];
        }

        // Connection-level failures (timeout, refused) have no JSON body.
        // Keep the first line, trimmed to a sane length.
        return Str::limit(strtok($message, "\n"), 120);
    }

    /**
     * The expanded explanation behind a notice. For the known restricted-
     * certificate case this describes the cause and the administrator's
     * options. A restricted certificate cannot raise its own level of access
     * through the API; that is an Incus guarantee this product relies on, so
     * the remedy always rests with the cluster's administrator.
     */
    private function noticeDetail(string $reason, ?string $version, Cluster $cluster): string
    {
        $detail = 'The server declined the request: '.lcfirst($reason).'.';

        if (stripos($reason, 'restricted') === false) {
            return $detail;
        }

        $fp = $this->certFingerprint($cluster);

        $detail .= ' This cluster runs Incus '.($version ?? 'of an unknown version')
            .', which does not permit a restricted certificate to view storage information.'
            .' Incus 7 and later provide a filtered view instead.'
            .' This data becomes available if the cluster\'s administrator upgrades Incus,'
            .' or grants the Kixctl certificate'
            .($fp ? ' (fingerprint '.$fp.')' : '')
            .' unrestricted access in the cluster\'s trust settings.'
            .' Unrestricted access allows Kixctl to manage everything on that server,'
            .' so that decision belongs to the administrator.'
            .' Kixctl cannot raise its own level of access.';

        return $detail;
    }

    /** SHA-256 fingerprint of our stored client certificate, as the trust store identifies it. */
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
