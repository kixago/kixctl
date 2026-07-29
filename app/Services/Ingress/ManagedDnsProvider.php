<?php

namespace App\Services\Ingress;

use App\Models\AppRoute;
use App\Models\IngressSetting;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The shipped default. kixctl provisions and owns a CoreDNS resolver, and every
 * change to the live routes is published by regenerating one zonefile and
 * pushing it into that container with IncusClient::pushInstanceFile. CoreDNS's
 * `file` plugin reloads on the SOA serial bump — no restart, no admin API, no
 * Caddy reload. Caddy stays autonomous and resolves <app>.<zone> against this
 * resolver. "Data, not config" (decision D14), end to end.
 */
class ManagedDnsProvider implements IngressProvider
{
    public function __construct(
        private IncusClient $incus,
        private ClusterRegistry $registry,
        private CorednsProvisioner $provisioner,
    ) {}

    public function publish(string $app, string $instance, string $ip, int $port): void
    {
        $settings = IngressSetting::current();

        AppRoute::query()->updateOrCreate(
            ['app' => $app],
            [
                'host' => $settings->hostFor($app),
                'live_instance' => $instance,
                'ip' => $ip,
                'port' => $port,
            ],
        );

        $this->render($settings);
    }

    public function withdraw(string $app): void
    {
        AppRoute::query()->where('app', $app)->delete();
        $this->render(IngressSetting::current());
    }

    public function syncAll(): void
    {
        $this->render(IngressSetting::current());
    }

    /**
     * Ensure the resolver exists, render the full zone from every AppRoute, and
     * push it. The single choke point every mutation funnels through.
     */
    private function render(IngressSetting $settings): void
    {
        $cluster = $this->cluster();

        $resolver = $this->provisioner->ensure($cluster, $settings);

        $records = AppRoute::query()->get()
            ->map(fn (AppRoute $r) => [
                'name' => $r->app,
                'ip' => (string) $r->ip,
                'ttl' => $settings->record_ttl,
            ])
            ->filter(fn (array $r) => $r['ip'] !== '')
            ->values()
            ->all();

        $zone = ZoneFile::build($settings->zone, $records, $settings->record_ttl);

        // The resolver may be brand-new (a rebuild just launched it): its zonefile
        // directory is created by systemd-tmpfiles at boot, and on a cluster the
        // member may not have registered the fresh instance for a beat. So ensure
        // the parent dir exists and retry across that short window — otherwise the
        // push 404s on a container that is still coming up. (launchBuiltImage guards
        // the credstore path the same way.)
        $zonefilePath = (string) config('ingress.managed.zonefile_path');
        $zoneDir = dirname($zonefilePath);

        retry(5, function () use ($cluster, $settings, $zoneDir, $zonefilePath, $zone): void {
            $this->incus->ensureInstanceDirectory($cluster, $settings->dns_instance, $zoneDir, 0755);
            $this->incus->pushInstanceFile(
                $cluster,
                $settings->dns_instance,
                $zonefilePath,
                $zone,
                0, 0, 0644,   // world-readable: CoreDNS runs as a DynamicUser
            );
        }, 500);

        Log::info('ingress.published', [
            'resolver' => $resolver['instance'],
            'resolver_ip' => $resolver['ip'],
            'records' => count($records),
        ]);
    }

    public function status(): array
    {
        $settings = IngressSetting::current();
        $name = $settings->dns_instance;

        try {
            $cluster = $this->cluster();
            $exists = $this->incus->instanceExists($cluster, $name);
            $ip = $exists ? $this->incus->instanceIpv4($cluster, $name) : null;
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'summary' => 'Cluster unreachable — cannot check the resolver.',
                'detail' => ['error' => $e->getMessage()],
            ];
        }

        return [
            'ready' => $exists && $ip !== null,
            'summary' => $exists
                ? ($ip !== null
                    ? "CoreDNS resolver running at {$ip}."
                    : "Resolver {$name} exists but has no IPv4 lease yet.")
                : "Resolver {$name} not provisioned yet — it is created on the first deploy.",
            'detail' => [
                'resolver' => $name,
                'resolver_ip' => (string) ($ip ?? ''),
                'zone' => $settings->zone,
                'point_caddy_at' => $ip !== null
                    ? "dynamic a {labels...}.{$settings->zone} — resolvers {$ip}"
                    : '(available once the resolver has an IP)',
            ],
        ];
    }

    private function cluster(): Cluster
    {
        $key = (string) config('deploy.launch.cluster', '');
        $cluster = $key !== '' ? $this->registry->find($key) : null;
        $cluster ??= collect($this->registry->all())->first();

        if (! $cluster) {
            throw new RuntimeException('No active cluster to provision ingress on.');
        }

        return $cluster;
    }
}
