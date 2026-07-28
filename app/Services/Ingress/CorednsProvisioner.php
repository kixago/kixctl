<?php

namespace App\Services\Ingress;

use App\Models\IngressSetting;
use App\Services\Incus\Cluster;
use App\Services\Incus\IncusClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Stands up (and locates) the CoreDNS resolver kixctl owns. Built through the
 * SAME kixctl-build path apps use — a local flake that imports kixctl-base and
 * adds services.coredns — then imported and launched over the Incus REST API.
 * Idempotent: if the resolver already exists it is simply located, not rebuilt.
 *
 * This is the one cluster-touching, environment-specific piece; everything it
 * calls (kixctl-build, importImage, launchBuiltImage, instanceIpv4) is proven.
 */
class CorednsProvisioner
{
    public function __construct(private IncusClient $incus) {}

    /**
     * Ensure the resolver exists and is running; return its instance name + IPv4.
     *
     * @return array{instance:string, ip:string}
     */
    public function ensure(Cluster $cluster, IngressSetting $settings): array
    {
        $name = $settings->dns_instance;

        if (! $this->incus->instanceExists($cluster, $name)) {
            $this->build($cluster, $settings, $name);
        }

        // Make sure it is running (a fresh launch starts it; an existing stopped
        // one is nudged). setInstanceState is idempotent on an already-running box.
        try {
            $this->incus->setInstanceState($cluster, $name, 'start', 60);
        } catch (\Throwable) {
            // already running / transient — IP probe below is the real gate
        }

        $ip = $this->waitForIp($cluster, $name);
        if ($ip === null) {
            throw new RuntimeException("CoreDNS resolver {$name} has no IPv4 lease yet.");
        }

        return ['instance' => $name, 'ip' => $ip];
    }

    /** Build the CoreDNS image from the local flake, import it, launch it. */
    private function build(Cluster $cluster, IngressSetting $settings, string $name): void
    {
        $flake = (string) config('ingress.managed.flake');
        $attr = (string) config('ingress.managed.flake_attr', 'coredns');

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

        $fingerprint = $this->incus->importImage(
            $cluster,
            $paths['metadata'],
            $paths['rootfs'],
            alias: $name,
        );

        // The resolver rides the configured profile (same one apps use by
        // default), so it lands on the network caddy-server already reaches. A
        // non-default `dns_network` is honored by selecting a profile that binds
        // it — network wiring lives in the profile, not an ad-hoc device here.
        $this->incus->launchBuiltImage(
            $cluster,
            $name,
            $fingerprint,
            $settings->dns_target,
            profiles: (array) config('ingress.managed.profiles', ['power']),
        );

        Log::info('ingress.coredns.launched', ['instance' => $name, 'target' => $settings->dns_target]);
    }

    private function waitForIp(Cluster $cluster, string $name, int $attempts = 20): ?string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $ip = $this->incus->instanceIpv4($cluster, $name);
            if ($ip !== null && $ip !== '') {
                return $ip;
            }
            usleep(500_000); // 0.5s between probes; DHCP lease can lag boot
        }

        return null;
    }
}
