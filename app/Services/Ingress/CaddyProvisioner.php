<?php

namespace App\Services\Ingress;

use App\Models\Network;
use App\Services\Incus\Cluster;
use App\Services\Incus\IncusClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Stands up (and locates) the Caddy edge kixctl owns — the third owned entity
 * beside kixbr0 and the `kix` profile, and the exact sibling of
 * CorednsProvisioner. It rides kixctl's OWN network (kixbr0) and OWN profile
 * (kix); nothing of the operator's — not caddy-server, not br0, not their LAN —
 * is read or touched. A fresh box with zero pre-existing kixctl anything: ensure
 * kixbr0, ensure kix, build/import/launch the edge onto both, read the lease.
 *
 * Config is delivered as DATA: kixctl renders a Caddyfile (CaddyfileRenderer)
 * and pushes it with pushInstanceFile — the same local-socket channel coredns
 * uses for its zonefile, which the smoke test proved works where host->bridge
 * HTTP does not. caddy --watch graceful-reloads on every push. Idempotent
 * throughout — an existing network/profile/edge is located, not recreated.
 *
 * @phpstan-type Progress callable(string $phase, string $message, array<string,mixed> $extra): void
 */
class CaddyProvisioner
{
    public function __construct(private IncusClient $incus) {}

    /**
     * Ensure the managed network + profile + Caddy edge exist and are running;
     * return the edge instance name, its IPv4, and the network it rides.
     *
     * $instance defaults to the configured owned edge (kixctl-caddy); the probe
     * passes a throwaway name to prove the loop in isolation. $onProgress and
     * $onConsole mirror CorednsProvisioner so the GUI can stream a live toast +
     * build console; synchronous callers pass nothing and get silent behavior.
     *
     * @param  callable|null  $onProgress
     * @param  callable|null  $onConsole
     * @return array{instance:string, ip:string, network:string}
     */
    public function ensure(Cluster $cluster, ?string $instance = null, ?callable $onProgress = null, ?callable $onConsole = null): array
    {
        $report = static function (string $phase, string $message, array $extra = []) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($phase, $message, $extra);
            }
        };

        $name = $instance ?: (string) config('ingress.caddy.instance', 'kixctl-caddy');
        $network = $this->resolveNetwork();

        // 1) kixctl's own bridge must exist before anything can ride it.
        $report('ensuring-network', "Ensuring network {$network->key}…", ['network' => $network->key]);
        $this->ensureNetwork($cluster, $network);

        // 2) kixctl's own profile (root disk on an auto-resolved pool).
        $profile = $this->ensureProfile($cluster, $report);

        // 3) Build + launch the edge onto kixctl's network + profile if absent.
        if (! $this->incus->instanceExists($cluster, $name)) {
            $this->build($cluster, $name, $network, $profile, $report, $onConsole);
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
            throw new RuntimeException("Caddy edge {$name} has no IPv4 lease yet on {$network->key}.");
        }

        $report('serving', "Serving at {$ip}.", ['ip' => $ip, 'network' => $network->key]);

        return ['instance' => $name, 'ip' => $ip, 'network' => $network->key];
    }

    /**
     * Push a rendered Caddyfile into the edge and let caddy --watch reload it.
     * The single choke point every route mutation funnels through — the caddy
     * analog of ManagedDnsProvider::render()'s zonefile push, guarded the same
     * way against a still-booting container (ensureInstanceDirectory + retry).
     */
    public function pushConfig(Cluster $cluster, string $instance, string $caddyfile): void
    {
        $configPath = (string) config('ingress.caddy.config_path', '/var/lib/kixctl-caddy/Caddyfile');
        $dir = dirname($configPath);

        retry(5, function () use ($cluster, $instance, $dir, $configPath, $caddyfile): void {
            $this->incus->ensureInstanceDirectory($cluster, $instance, $dir, 0755);
            $this->incus->pushInstanceFile(
                $cluster,
                $instance,
                $configPath,
                $caddyfile,
                0, 0, 0644,   // world-readable: the caddy DynamicUser/user reads it
            );
        }, 500);

        Log::info('ingress.caddy.pushed', [
            'instance' => $instance,
            'bytes' => strlen($caddyfile),
        ]);
    }

    /** Delete the edge instance and its image — used by the probe's teardown. */
    public function teardown(Cluster $cluster, string $instance): void
    {
        if ($this->incus->instanceExists($cluster, $instance)) {
            $this->incus->deleteInstance($cluster, $instance);
        }
        $fingerprint = $this->incus->imageFingerprintByAlias($cluster, $instance);
        if ($fingerprint !== null) {
            $this->incus->deleteImage($cluster, $fingerprint);
        }
    }

    /**
     * The network the edge rides: the configured caddy network if pinned,
     * otherwise the default network row (kixbr0). ensureDefault() guarantees a
     * row exists even on a box that was never seeded.
     */
    private function resolveNetwork(): Network
    {
        $pinned = (string) config('ingress.caddy.network', '');
        if ($pinned !== '') {
            $row = Network::query()->where('key', $pinned)->first();
            if ($row !== null) {
                return $row;
            }

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
     * created here; an unmanaged reference points at a network the user already
     * runs, which kixctl must never create. Idempotent. (Mirror of the coredns
     * provisioner's ensureNetwork so the caddy path is self-contained.)
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

        $this->incus->createNetwork(
            $cluster,
            $network->key,
            'bridge',
            $network->incusConfig(),
            $network->description,
        );
    }

    /**
     * Ensure kixctl's OWN profile exists and return its name — a root disk on an
     * auto-resolved pool. Never reads or mutates the operator's default/power.
     * Idempotent (an existing profile is adopted). Mirror of the coredns path.
     */
    private function ensureProfile(Cluster $cluster, callable $report): string
    {
        $name = (string) config('networks.profile.name', 'kix');

        $report('ensuring-profile', "Ensuring profile {$name}…", ['profile' => $name]);

        if ($this->incus->profileExists($cluster, $name)) {
            return $name;
        }

        $pool = $this->resolvePool($cluster);

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
     * Pick the storage pool for the root disk WITHOUT asking the operator: a
     * config pin wins; else a single pool as-is; else one named `default`; else
     * the first by name. Auto-discovery, not a borrowed setting. (Mirror.)
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

    /** Build the Caddy image from the local flake, import it (replace), launch it. */
    private function build(Cluster $cluster, string $name, Network $network, string $profile, callable $report, ?callable $onConsole = null): void
    {
        $flake = (string) config('ingress.caddy.flake');
        $attr = (string) config('ingress.caddy.flake_attr', 'caddy');
        $target = (string) config('ingress.caddy.target', 'powerhouse');

        $report('building', 'Building the Caddy edge image…');
        Log::info('ingress.caddy.build', ['flake' => $flake, 'attr' => $attr]);

        $result = (new \App\Support\ConsoleStreamer())->run(
            [
                base_path('scripts/kixctl-build'),
                '--flake', $flake,
                '--attr', $attr,
                '--kind', 'container',
            ],
            static function (string $stream, string $line) use ($onConsole): void {
                if ($onConsole !== null) {
                    $onConsole($stream, $line);
                }
            },
            1800,
        );

        if (! $result->successful()) {
            throw new RuntimeException('Caddy build failed: '.$result->errorOutput());
        }

        $paths = json_decode(trim($result->output()), true);
        if (! is_array($paths) || empty($paths['metadata']) || empty($paths['rootfs'])) {
            throw new RuntimeException('Caddy build produced no image paths: '.$result->output());
        }

        // replace: true — the edge reuses ONE fixed alias across rebuilds, so we
        // must delete the stale image and import the fresh content (the same
        // fossil-image guard the resolver uses).
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
            $target,
            profiles: [$profile],
            network: $network->key,
        );

        Log::info('ingress.caddy.launched', [
            'instance' => $name,
            'target' => $target,
            'network' => $network->key,
            'profile' => $profile,
        ]);
    }

    /**
     * Poll for the DHCP lease. A fresh container boots systemd, starts networkd,
     * matches eth0, DISCOVERs and leases — a cold path that routinely exceeds 10s.
     * Wait up to ~90s (180 × 0.5s), ticking every ~5s so a slow boot reads as
     * "still leasing", not a hang. (Mirror of the coredns waitForIp.)
     */
    private function waitForIp(Cluster $cluster, string $name, int $attempts = 180, ?callable $report = null): ?string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $ip = $this->incus->instanceIpv4($cluster, $name);
            if ($ip !== null && $ip !== '') {
                return $ip;
            }
            if ($report !== null && $i > 0 && $i % 10 === 0) {
                $report('leasing', 'Waiting for a DHCP lease…', ['elapsed' => (int) ($i * 0.5)]);
            }
            usleep(500_000);
        }

        return null;
    }
}
