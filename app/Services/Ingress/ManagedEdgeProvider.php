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
 * The self-contained edge. Extends the managed model one hop: kixctl owns a
 * Caddy edge (CaddyProvisioner) AND the CoreDNS resolver, and a single publish
 * writes BOTH artifacts from app_routes so a saved record lights up end to end:
 *
 *   - CoreDNS: <app>.<zone>  ->  the CADDY edge's IP   (every app name resolves
 *     to the one edge; the resolver stops pointing at app IPs directly).
 *   - Caddy:   http://<app>.<zone>  ->  reverse_proxy <app ip>:<port>   (the edge
 *     fans out by Host header to the actual app container).
 *
 * The loop is entirely on kixbr0 — owned caddy <-> owned DNS <-> apps — so it
 * never touches the operator's caddy-server or br0. This is the provider the GUI
 * selects when the user opts into the owned edge; the plain `managed` provider
 * (DNS -> app directly, autonomous external caddy) is untouched and still the
 * default.
 *
 * Both renderers (CaddyfileRenderer, ZoneFile) and both delivery paths (the
 * Incus files API push) are already probe-proven; this provider is their
 * composition, proven end to end by kixctl:edge-probe.
 */
class ManagedEdgeProvider implements IngressProvider
{
    public function __construct(
        private IncusClient $incus,
        private ClusterRegistry $registry,
        private CorednsProvisioner $resolver,
        private CaddyProvisioner $caddy,
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
     * The single choke point: ensure both owned units, then push the Caddyfile
     * (host -> app) and the zonefile (host -> caddy) rendered from every route.
     * Optional progress/console callbacks let the async job stream a spinner +
     * the build log; synchronous callers pass nothing.
     *
     * @param  callable|null  $onProgress
     * @param  callable|null  $onConsole
     */
    public function render(IngressSetting $settings, ?callable $onProgress = null, ?callable $onConsole = null): void
    {
        $cluster = $this->cluster();

        // 1) Ensure the owned Caddy edge and learn its IP — the DNS target.
        $caddyState = $this->caddy->ensure($cluster, null, $onProgress, $onConsole);
        $caddyIp = $caddyState['ip'];

        // 2) Ensure the resolver (unchanged from the managed path).
        $resolver = $this->resolver->ensure($cluster, $settings, $onProgress, $onConsole);

        $routes = AppRoute::query()->get();

        // 3) Caddy: host -> app ip:port. Push and let caddy --watch reload.
        $caddyfile = CaddyfileRenderer::build(
            $routes->map(fn (AppRoute $r) => [
                'host' => (string) $r->host,
                'ip' => (string) $r->ip,
                'port' => (int) $r->port,
            ])->all(),
        );
        $this->caddy->pushConfig($cluster, $caddyState['instance'], $caddyfile);

        // 4) CoreDNS: every app label -> the CADDY ip (not the app ip). This is
        //    the one behavioural difference from ManagedDnsProvider — the edge is
        //    the single address all app names resolve to.
        $records = $routes
            ->map(fn (AppRoute $r) => [
                'name' => (string) $r->app,
                'ip' => $caddyIp,
                'ttl' => (int) $settings->record_ttl,
            ])
            ->all();

        $zone = ZoneFile::build($settings->zone, $records, $settings->record_ttl);
        $this->pushZone($cluster, $settings, $zone);

        Log::info('ingress.edge.published', [
            'caddy' => $caddyState['instance'],
            'caddy_ip' => $caddyIp,
            'resolver' => $resolver['instance'],
            'routes' => $routes->count(),
        ]);
    }

    /**
     * Push the zonefile into the resolver, guarded against a still-booting
     * container exactly like ManagedDnsProvider (ensureInstanceDirectory + retry
     * across the short window where a fresh instance 404s).
     */
    private function pushZone(Cluster $cluster, IngressSetting $settings, string $zone): void
    {
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
    }

    public function status(): array
    {
        $settings = IngressSetting::current();
        $caddyName = (string) config('ingress.caddy.instance', 'kixctl-caddy');
        $resolverName = $settings->dns_instance;

        try {
            $cluster = $this->cluster();
            $caddyExists = $this->incus->instanceExists($cluster, $caddyName);
            $caddyIp = $caddyExists ? $this->incus->instanceIpv4($cluster, $caddyName) : null;
            $resolverExists = $this->incus->instanceExists($cluster, $resolverName);
            $resolverIp = $resolverExists ? $this->incus->instanceIpv4($cluster, $resolverName) : null;
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'summary' => 'Cluster unreachable — cannot check the edge.',
                'detail' => ['error' => $e->getMessage()],
            ];
        }

        $ready = $caddyExists && $caddyIp !== null && $resolverExists && $resolverIp !== null;

        return [
            'ready' => $ready,
            'summary' => $ready
                ? "Owned edge running — Caddy at {$caddyIp}, resolver at {$resolverIp}."
                : 'Owned edge not fully up yet — it is created on the first publish.',
            'detail' => [
                'caddy' => $caddyName,
                'caddy_ip' => (string) ($caddyIp ?? ''),
                'resolver' => $resolverName,
                'resolver_ip' => (string) ($resolverIp ?? ''),
                'zone' => $settings->zone,
                'model' => "<app>.{$settings->zone} -> caddy -> app (all internal, on kixbr0)",
            ],
        ];
    }

    private function cluster(): Cluster
    {
        $key = (string) config('deploy.launch.cluster', '');
        $cluster = $key !== '' ? $this->registry->find($key) : null;
        $cluster ??= collect($this->registry->all())->first();

        if (! $cluster) {
            throw new RuntimeException('No active cluster to provision the edge on.');
        }

        return $cluster;
    }
}
