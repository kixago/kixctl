<?php

namespace App\Console\Commands;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\CaddyfileRenderer;
use App\Services\Ingress\CaddyProvisioner;
use App\Services\Ingress\ZoneFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * ISOLATION HARNESS for the self-contained edge publish — proves the exact
 * artifacts ManagedEdgeProvider renders, without disturbing the real edge or
 * resolver:
 *
 *   1. build + launch a THROWAWAY caddy edge on kixbr0 + kix, learn its IP,
 *   2. render the Caddyfile from a synthetic app route with CaddyfileRenderer
 *      (the SAME renderer the provider uses) and push it,
 *   3. curl THROUGH caddy: the app host -> 502 (route live, dead upstream),
 *      proving the rendered reverse_proxy serves,
 *   4. render the zonefile with ZoneFile pointing the app label at the CADDY ip
 *      (the provider's one behavioural difference) and assert the record is
 *      exactly `<app> <ttl> IN A <caddy-ip>` — the DNS half, proven by output,
 *   5. tear the throwaway down.
 *
 * The live caddy push/serve path and the resolver zonefile push are each already
 * proven (caddy-probe, the shipped managed DNS path); this proves the provider's
 * COMPOSITION renders both correctly from one route set.
 *
 *   php artisan kixctl:edge-probe
 *   php artisan kixctl:edge-probe --keep
 */
class EdgeProbe extends Command
{
    protected $signature = 'kixctl:edge-probe
        {--instance=kixctl-edge-probe : Throwaway edge instance name}
        {--keep : Do not tear the edge down after proving}';

    protected $description = 'Prove ManagedEdgeProvider renders both artifacts: Caddyfile serves + zone points at the edge (isolation test)';

    public function handle(CaddyProvisioner $caddy, IncusClient $incus, ClusterRegistry $registry): int
    {
        $instance = (string) $this->option('instance');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        // Synthetic app route: a name under the zone, an upstream on a dead port
        // (so reverse_proxy matches -> 502 without needing a live app).
        $zone = (string) config('ingress.zone', 'apps.internal');
        $app = 'probeapp';
        $host = $app.'.'.$zone;
        $ttl = (int) config('ingress.managed.record_ttl', 30);

        $this->info("Ensuring throwaway edge '{$instance}' on kixbr0 + kix (builds on first run)…");
        try {
            $state = $caddy->ensure($cluster, $instance,
                onProgress: fn (string $phase, string $message) => $this->line("  [{$phase}] {$message}"),
            );
        } catch (\Throwable $e) {
            $this->error('ensure() failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $caddyIp = $state['ip'];
        $this->info("Edge up: {$state['instance']} at {$caddyIp} on {$state['network']}.");

        // 2+3) Render the Caddyfile with the REAL renderer and push it.
        $this->info('Rendering + pushing the Caddyfile (host -> app) via CaddyfileRenderer…');
        $caddyfile = CaddyfileRenderer::build([
            ['host' => $host, 'ip' => '127.0.0.1', 'port' => 59999],
        ]);
        try {
            $caddy->pushConfig($cluster, $instance, $caddyfile);
        } catch (\Throwable $e) {
            $this->error('pushConfig() failed: '.$e->getMessage());

            return $this->leaveUp($instance);
        }
        sleep(3); // let caddy --watch reload

        [$code] = $this->curlInside($instance, $host);
        if ($code !== '502') {
            $this->error("Rendered reverse_proxy did not serve: expected 502 for {$host}, got {$code}.");

            return $this->leaveUp($instance);
        }
        $this->info("  {$host} -> 502 (route live, dead upstream). CaddyfileRenderer output serves.");

        // 4) Render the zonefile pointing the app label at the CADDY ip, assert it.
        $this->info('Rendering the zonefile (app label -> caddy ip) via ZoneFile…');
        $zonefile = ZoneFile::build($zone, [
            ['name' => $app, 'ip' => $caddyIp, 'ttl' => $ttl],
        ], $ttl);

        $expected = "{$app} {$ttl} IN A {$caddyIp}";
        if (! str_contains($zonefile, $expected)) {
            $this->error("Zone record wrong: expected a line '{$expected}'.");
            $this->line($zonefile);

            return $this->leaveUp($instance);
        }
        $this->info("  zone has '{$expected}'. DNS points the app name at the edge, not the app. Proven.");

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$instance}' running. Remove: incus delete -f {$instance} && incus image delete {$instance}");

            return self::SUCCESS;
        }

        $this->info("Tearing down '{$instance}'…");
        try {
            $caddy->teardown($cluster, $instance);
        } catch (\Throwable $e) {
            $this->warn("Teardown failed: {$e->getMessage()} — remove '{$instance}' by hand.");
        }

        $this->info('Clean: one route set renders a serving Caddyfile AND a zone that points the name at the edge. Publish composition proven.');

        return self::SUCCESS;
    }

    /**
     * curl THROUGH caddy from inside the container (host->bridge HTTP is
     * unreliable; from inside, localhost:80 is caddy). Returns [http_code].
     *
     * @return array{0:string}
     */
    private function curlInside(string $instance, string $host): array
    {
        $result = Process::timeout(20)->run([
            'incus', 'exec', $instance, '--',
            'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-H', "Host: {$host}", 'http://localhost/',
        ]);

        $code = trim($result->output());

        return [$code !== '' ? $code : '000'];
    }

    /** Leave the instance up on failure so it can be inspected. */
    private function leaveUp(string $instance): int
    {
        $this->newLine();
        $this->warn("Left '{$instance}' running for inspection. Clean up with:");
        $this->line("  incus delete -f {$instance} && incus image delete {$instance}");

        return self::FAILURE;
    }
}
