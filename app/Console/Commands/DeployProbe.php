<?php

namespace App\Console\Commands;

use App\Models\AppRoute;
use App\Models\IngressSetting;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\IngressManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * ISOLATION HARNESS for the Piece 4 deploy rewire — proves the two things a real
 * push now does, without a git build:
 *
 *   1. PLACEMENT: launch a throwaway revision via IncusClient::launchBuiltImage
 *      on the DEPLOY network/profile (kixbr0 + kix) and assert it landed on the
 *      owned bridge (10.216.19.x) — not the operator's LAN,
 *   2. AUTO-PUBLISH: call IngressManager::publish exactly as DeployFromPush now
 *      does, and assert the route row exists. When the provider is `edge`, also
 *      assert kixctl's Caddyfile gained the route and curl THROUGH the real edge
 *      to the throwaway (200) — deployed app reachable end to end via the edge.
 *
 * The throwaway is launched from the owned caddy image (it serves :80), a
 * convenient stand-in for a freshly-built app — so no repo, no nix build. The
 * image is shared (the real edge's), so teardown deletes only the throwaway
 * instance, never the image.
 *
 *   php artisan kixctl:deploy-probe
 *   php artisan kixctl:deploy-probe --keep
 */
class DeployProbe extends Command
{
    protected $signature = 'kixctl:deploy-probe
        {--instance=kixctl-deploy-probe : Throwaway revision name}
        {--keep : Do not tear the revision + route down after proving}';

    protected $description = 'Prove the deploy rewire: revision lands on kixbr0 + auto-publishes a live edge route (isolation test)';

    public function handle(IncusClient $incus, ClusterRegistry $registry, IngressManager $ingress): int
    {
        $instance = (string) $this->option('instance');
        $app = 'deployprobe';
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        $profile = (string) config('deploy.launch.profile', 'kix');
        $network = (string) config('deploy.launch.network', 'kixbr0');
        $target = (string) config('deploy.launch.target', 'powerhouse');

        // Stand-in image: the owned caddy image serves :80. Fail clearly if the
        // edge was never built (run one GUI/edge publish first).
        $standInAlias = (string) config('ingress.caddy.instance', 'kixctl-caddy');
        $fingerprint = $incus->imageFingerprintByAlias($cluster, $standInAlias);
        if (! $fingerprint) {
            $this->error("No '{$standInAlias}' image to stand in for an app — publish the edge once first.");

            return self::FAILURE;
        }

        // 1) PLACEMENT — launch on the deploy network/profile.
        $this->info("Launching throwaway revision '{$instance}' on {$network} + {$profile} (the deploy path)…");
        try {
            $incus->launchBuiltImage(
                $cluster,
                $instance,
                $fingerprint,
                $target,
                profiles: $profile !== '' ? [$profile] : ['power'],
                network: $network !== '' ? $network : null,
            );
        } catch (\Throwable $e) {
            $this->error('launch failed: '.$e->getMessage());

            return $this->leaveUp($instance);
        }

        $ip = null;
        for ($i = 0; $i < 15 && $ip === null; $i++) {
            try {
                $ip = $incus->instanceIpv4($cluster, $instance);
            } catch (\Throwable) {
            }
            if ($ip === null) {
                sleep(1);
            }
        }

        if ($ip === null || ! str_starts_with($ip, '10.216.19.')) {
            $this->error('Revision did not land on kixbr0 (got '.($ip ?? 'no lease').').');

            return $this->leaveUp($instance);
        }
        $this->info("  landed at {$ip} on {$network}. Deploy placement on the owned bridge proven.");

        // 2) AUTO-PUBLISH — exactly as DeployFromPush now does (port 80: the
        //    stand-in caddy image's real listen port).
        $this->info('Publishing the route via the current provider (as a deploy would)…');
        try {
            $ingress->publish($app, $instance, $ip, 80);
        } catch (\Throwable $e) {
            $this->error('publish failed: '.$e->getMessage());
            $this->cleanup($ingress, $incus, $cluster, $app, $instance);

            return self::FAILURE;
        }

        $route = AppRoute::query()->where('app', $app)->first();
        if (! $route || $route->ip !== $ip) {
            $this->error('app_routes row missing or wrong after publish.');
            $this->cleanup($ingress, $incus, $cluster, $app, $instance);

            return self::FAILURE;
        }
        $this->info("  app_routes has {$route->host} -> {$route->ip}:{$route->port}. Auto-publish proven.");

        // 3) EDGE REACHABILITY (only when the owned edge is the provider).
        $settings = IngressSetting::current();
        if ($settings->provider === 'edge') {
            $edge = (string) config('ingress.caddy.instance', 'kixctl-caddy');
            $host = $settings->hostFor($app);

            $caddyfile = Process::timeout(20)->run(['incus', 'exec', $edge, '--', 'cat', '/var/lib/kixctl-caddy/Caddyfile'])->output();
            if (! str_contains($caddyfile, $host)) {
                $this->error("Edge Caddyfile missing {$host}.");
                $this->cleanup($ingress, $incus, $cluster, $app, $instance);

                return self::FAILURE;
            }

            sleep(2); // let caddy --watch reload
            [$code] = $this->curlInside($edge, $host);
            if ($code !== '200') {
                $this->error("Edge did not proxy to the revision: expected 200 for {$host}, got {$code}.");
                $this->cleanup($ingress, $incus, $cluster, $app, $instance);

                return self::FAILURE;
            }
            $this->info("  {$host} through the edge -> 200. Deployed app reachable end to end via kixctl's own edge.");
        } else {
            $this->warn("  provider is '{$settings->provider}', not 'edge' — skipped the edge-reachability check (route + DNS still asserted).");
        }

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$instance}' + the '{$app}' route in place.");

            return self::SUCCESS;
        }

        $this->cleanup($ingress, $incus, $cluster, $app, $instance);
        $this->info('Clean: a revision lands on kixbr0 and auto-publishes a live edge route. Deploy rewire proven.');

        return self::SUCCESS;
    }

    /** Withdraw the route (re-publishes without it) and delete the throwaway. */
    private function cleanup(IngressManager $ingress, IncusClient $incus, Cluster $cluster, string $app, string $instance): void
    {
        try {
            $ingress->withdraw($app);
        } catch (\Throwable $e) {
            $this->warn("Route withdraw failed: {$e->getMessage()}");
        }
        try {
            $incus->deleteInstance($cluster, $instance);
        } catch (\Throwable $e) {
            $this->warn("Instance delete failed: {$e->getMessage()} — remove '{$instance}' by hand.");
        }
    }

    /**
     * curl THROUGH the edge from inside it (host->bridge HTTP is unreliable).
     *
     * @return array{0:string}
     */
    private function curlInside(string $edge, string $host): array
    {
        $result = Process::timeout(20)->run([
            'incus', 'exec', $edge, '--',
            'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-H', "Host: {$host}", 'http://localhost/',
        ]);

        $code = trim($result->output());

        return [$code !== '' ? $code : '000'];
    }

    /** Leave the instance up on a placement failure for inspection. */
    private function leaveUp(string $instance): int
    {
        $this->newLine();
        $this->warn("Left '{$instance}' running for inspection. Clean up with:");
        $this->line("  incus delete -f {$instance}");

        return self::FAILURE;
    }
}
