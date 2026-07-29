<?php

namespace App\Services\Ingress;

use App\Models\IngressSetting;
use App\Models\Network;
use App\Services\Incus\Cluster;
use App\Services\Incus\IncusClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Stands up (and locates) the CoreDNS resolver kixctl owns — on kixctl's OWN
 * network AND kixctl's OWN profile. This is the pivot, in full: kixctl never
 * borrows the operator's topology. It owns kixbr0 (its subnet/DHCP/NAT come
 * from Incus) and it owns the `kix` profile (a root disk on an auto-resolved
 * pool). The resolver's eth0 is an explicit NIC on kixbr0. Nothing of the
 * operator's — not `default`, not `power`, not their LAN — is read or touched.
 *
 * A fresh box with zero pre-existing kixctl anything: create kixbr0, create the
 * `kix` profile, build/import/launch the resolver onto both, read the lease.
 * Each step is reported through an optional progress callback so the GUI streams
 * a live toast instead of a frozen spinner. Idempotent throughout — an existing
 * network/profile/resolver is located, not recreated.
 *
 * @phpstan-type Progress callable(string $phase, string $message, array<string,mixed> $extra): void
 */
class CorednsProvisioner
{
    public function __construct(private IncusClient $incus) {}

    /**
     * Ensure the managed network + profile + resolver exist and are running;
     * return the resolver instance name, its IPv4, and the network it rides.
     *
     * $onProgress (optional) is called at each step boundary as
     * fn(string $phase, string $message, array $extra). Synchronous callers
     * (DeployFromPush) pass nothing and get identical, silent behavior.
     *
     * @param  callable|null  $onProgress
     * @return array{instance:string, ip:string, network:string}
     */
    public function ensure(Cluster $cluster, IngressSetting $settings, ?callable $onProgress = null): array
    {
        $report = static function (string $phase, string $message, array $extra = []) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($phase, $message, $extra);
            }
        };

        $network = $this->resolveNetwork($settings);

        // 1) kixctl's own bridge must exist before anything can ride it.
        $report('ensuring-network', "Ensuring network {$network->key}…", ['network' => $network->key]);
        $this->ensureNetwork($cluster, $network);

        // 2) kixctl's own profile (root disk on an auto-resolved pool). Never the
        //    operator's default/power.
        $profile = $this->ensureProfile($cluster, $report);

        $name = $settings->dns_instance;

        // 3) Build + launch the resolver onto kixctl's network + profile if absent.
        if (! $this->incus->instanceExists($cluster, $name)) {
            $this->build($cluster, $settings, $name, $network, $profile, $report);
        }

        // 4) Make sure it is running. Idempotent on an already-running box.
        $report('starting', "Starting {$name}…");
        try {
            $this->incus->setInstanceState($cluster, $name, 'start', 60);
        } catch (\Throwable) {
            // already running / transient — the lease probe below is the real gate
        }

        // 5) Wait for the kixbr0 lease.
        $report('leasing', 'Waiting for a DHCP lease…');
        $ip = $this->waitForIp($cluster, $name, report: $report);
        if ($ip === null) {
            throw new RuntimeException("CoreDNS resolver {$name} has no IPv4 lease yet on {$network->key}.");
        }

        $report('serving', "Serving at {$ip}.", ['ip' => $ip, 'network' => $network->key]);

        return ['instance' => $name, 'ip' => $ip, 'network' => $network->key];
    }

    /**
     * The network the resolver rides: the ingress `dns_network` if the operator
     * pinned one, otherwise the default network row (kixbr0). ensureDefault()
     * guarantees a row exists even on a box that was never seeded.
     */
    private function resolveNetwork(IngressSetting $settings): Network
    {
        $pinned = $settings->dns_network;
        if ($pinned !== null && $pinned !== '') {
            $row = Network::query()->where('key', $pinned)->first();
            if ($row !== null) {
                return $row;
            }
            // A pinned key with no row yet: treat it as a managed bridge to create.
            return new Network(array_merge(Network::defaults(), [
                'key' => $pinned,
                'label' => $pinned,
                'is_default' => false,
            ]));
        }

        return Network::default() ?? Network::ensureDefault();
    }

    /**
     * Ensure the Incus managed bridge exists. Only kixctl-managed rows are ever
     * created here — an unmanaged reference row (managed=false) points at a
     * network the user already runs, which kixctl must never create or mutate.
     * Create is idempotent: an already-present network is left as-is.
     */
    private function ensureNetwork(Cluster $cluster, Network $network): void
    {
        if ($this->incus->networkExists($cluster, $network->key)) {
            return;
        }

        if (! $network->managed) {
            throw new RuntimeException(
                "Network {$network->key} is registered as unmanaged but does not exist on the cluster; kixctl will not create it."
            );
        }

        Log::info('networks.create', ['key' => $network->key, 'config' => $network->incusConfig()]);

        $this->incus->createNetwork(
            $cluster,
            $network->key,
            'bridge',
            $network->incusConfig(),
            $network->description,
        );
    }

    /**
     * Ensure kixctl's OWN profile exists and return its name. The profile carries
     * only a root disk on an auto-resolved pool — the network comes from the
     * instance NIC, not here. kixctl never reads or mutates the operator's
     * profiles; it only creates its own, and only if it is missing.
     */
    private function ensureProfile(Cluster $cluster, callable $report): string
    {
        $name = (string) config('networks.profile.name', 'kix');

        $report('ensuring-profile', "Ensuring profile {$name}…", ['profile' => $name]);

        if ($this->incus->profileExists($cluster, $name)) {
            return $name;
        }

        $pool = $this->resolvePool($cluster);

        Log::info('networks.profile.create', ['profile' => $name, 'pool' => $pool]);

        $this->incus->createProfile(
            $cluster,
            $name,
            devices: [
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => $pool],
            ],
            description: "kixctl-owned baseline: a root disk on '{$pool}'. Network comes from the instance NIC (kixbr0), not this profile.",
        );

        return $name;
    }

    /**
     * Pick the storage pool for kixctl's root disks WITHOUT asking the operator:
     * an explicit config pin wins; otherwise a single pool is used as-is; a pool
     * named `default` is preferred when there are several; else the first by
     * name. This is auto-discovery from the cluster, not a borrowed setting.
     */
    private function resolvePool(Cluster $cluster): string
    {
        $pinned = (string) config('networks.profile.pool', '');
        if ($pinned !== '') {
            return $pinned;
        }

        $pools = collect($this->incus->storagePools($cluster))
            ->pluck('name')
            ->filter()
            ->values();

        if ($pools->isEmpty()) {
            throw new RuntimeException(
                'No Incus storage pool found on the cluster; create one (e.g. `incus admin init`) before kixctl can provision, or set KIXCTL_POOL.'
            );
        }

        if ($pools->count() === 1) {
            return (string) $pools->first();
        }

        if ($pools->contains('default')) {
            return 'default';
        }

        return (string) $pools->sort()->values()->first();
    }

    /** Build the CoreDNS image from the local flake, import it, launch it on kixctl's net + profile. */
    private function build(Cluster $cluster, IngressSetting $settings, string $name, Network $network, string $profile, callable $report): void
    {
        $flake = (string) config('ingress.managed.flake');
        $attr = (string) config('ingress.managed.flake_attr', 'coredns');

        $report('building', 'Building the resolver image…');
        Log::info('ingress.coredns.build', ['flake' => $flake, 'attr' => $attr]);

        $result = Process::timeout(1800)->run([
            base_path('scripts/kixctl-build'),
            '--flake', $flake,
            '--attr', $attr,
            '--kind', 'container',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('CoreDNS build failed: '.$result->errorOutput());
        }

        $paths = json_decode(trim($result->output()), true);
        if (! is_array($paths) || empty($paths['metadata']) || empty($paths['rootfs'])) {
            throw new RuntimeException('CoreDNS build produced no image paths: '.$result->output());
        }

        // replace: true — the resolver reuses ONE fixed alias across rebuilds, so
        // we must delete the stale image and import the freshly built content;
        // otherwise importImage's per-revision short-circuit relaunches the old
        // image forever (the fossil-image bug).
        $report('importing', 'Importing the image…');
        $fingerprint = $this->incus->importImage(
            $cluster,
            $paths['metadata'],
            $paths['rootfs'],
            alias: $name,
            replace: true,
        );

        // Root disk from kixctl's OWN profile; network from the instance eth0 NIC
        // on the kixctl-managed bridge. Neither borrowed from the operator.
        $report('launching', "Launching {$name} on {$network->key}…", ['network' => $network->key]);
        $this->incus->launchBuiltImage(
            $cluster,
            $name,
            $fingerprint,
            $settings->dns_target,
            profiles: [$profile],
            network: $network->key,
        );

        Log::info('ingress.coredns.launched', [
            'instance' => $name,
            'target' => $settings->dns_target,
            'network' => $network->key,
            'profile' => $profile,
        ]);
    }

    /**
     * Poll for the DHCP lease. A FRESH container has to boot systemd, start
     * networkd, match eth0, DISCOVER and lease — a cold path that routinely
     * exceeds the old 10s budget even though the lease itself is instant once
     * asked. So we wait up to ~90s (180 × 0.5s) and, if a progress callback is
     * given, tick every ~5s so a slow boot reads as "still leasing", not a hang.
     */
    private function waitForIp(Cluster $cluster, string $name, int $attempts = 180, ?callable $report = null): ?string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $ip = $this->incus->instanceIpv4($cluster, $name);
            if ($ip !== null && $ip !== '') {
                return $ip;
            }
            if ($report !== null && $i > 0 && $i % 10 === 0) {
                $report('leasing', 'Waiting for a DHCP lease… ('.((int) ($i / 2)).'s)');
            }
            usleep(500_000); // 0.5s between probes
        }

        return null;
    }
}
