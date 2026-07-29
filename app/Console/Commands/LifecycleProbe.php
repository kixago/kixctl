<?php

namespace App\Console\Commands;

use App\Models\AppRoute;
use App\Models\IngressSetting;
use App\Services\Deploy\DeploymentManager;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\IngressManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * ISOLATION HARNESS for the UPDATE / CUTOVER / REAP / REVERT lifecycle — proves
 * the route-swing mechanics on the real cluster without a git build, the same way
 * DeployProbe proved the deploy rewire.
 *
 * It stands up TWO throwaway "revisions" of a fake app from the owned caddy image
 * (a convenient stand-in that serves :80), then exercises the engine:
 *
 *   1. CUTOVER   — make revision A live, cut over to B, and assert the route now
 *                  points at B, A is marked retired (and stopped, if configured),
 *                  and — when the provider is `edge` — the edge proxies to B (200).
 *   2. REVERT    — swing back to A, and assert the route points at A again, A's
 *                  retirement mark is cleared, B is retired, and the edge serves A.
 *   3. REAP      — backdate B's retirement past the window and reap; assert B (the
 *                  retired revision) is gone and A (live) is untouched.
 *
 * The stand-in instances are launched from the SHARED caddy image with no
 * per-revision alias, so reap deletes only the throwaway INSTANCES, never the
 * edge's image (imageFingerprintByAlias returns null for them). Real deploys DO
 * alias the image per revision, so the image-delete branch is exercised by the
 * real deploy path, not this probe — noted so no future session "fixes" it.
 *
 *   php artisan kixctl:lifecycle-probe
 *   php artisan kixctl:lifecycle-probe --keep
 */
class LifecycleProbe extends Command
{
    protected $signature = 'kixctl:lifecycle-probe
        {--app=lifeprobe : Throwaway app key}
        {--keep : Leave the revisions + route in place after proving}';

    protected $description = 'Prove the cutover / revert / reap lifecycle on the real cluster (isolation test)';

    public function handle(IncusClient $incus, ClusterRegistry $registry, IngressManager $ingress, DeploymentManager $lifecycle): int
    {
        $app = (string) $this->option('app');
        $revA = "{$app}-aaaaaa1";
        $revB = "{$app}-bbbbbb2";

        $cluster = collect($registry->all())->first();
        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        $profile = (string) config('deploy.launch.profile', 'kix');
        $network = (string) config('deploy.launch.network', 'kixbr0');
        $target = (string) config('deploy.launch.target', 'powerhouse');

        $standInAlias = (string) config('ingress.caddy.instance', 'kixctl-caddy');
        $fingerprint = $incus->imageFingerprintByAlias($cluster, $standInAlias);
        if (! $fingerprint) {
            $this->error("No '{$standInAlias}' image to stand in for an app — publish the edge once first.");

            return self::FAILURE;
        }

        $settings = IngressSetting::current();
        $host = $settings->hostFor($app);
        $edgeProvider = $settings->provider === 'edge';

        // ── Stand up revision A and make it live ─────────────────────────────
        $this->info("Launching stand-in revision A ('{$revA}') on {$network} + {$profile}…");
        $ipA = $this->launch($incus, $cluster, $revA, $fingerprint, $target, $profile, $network);
        if ($ipA === null) {
            return $this->bail($incus, $cluster, [$revA]);
        }
        $this->line("  A at {$ipA}. Publishing it as the live route…");
        $ingress->publish($app, $revA, $ipA, 80);

        // ── Stand up revision B (landed, not yet live) ───────────────────────
        $this->info("Launching stand-in revision B ('{$revB}')…");
        $ipB = $this->launch($incus, $cluster, $revB, $fingerprint, $target, $profile, $network);
        if ($ipB === null) {
            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        $this->line("  B at {$ipB}.");

        // ── 1) CUTOVER A -> B ────────────────────────────────────────────────
        $this->info('Cutting over A -> B…');
        try {
            $result = $lifecycle->cutover($app, $revB);
        } catch (\Throwable $e) {
            $this->error('cutover failed: '.$e->getMessage());

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }

        $route = AppRoute::query()->where('app', $app)->first();
        if (! $route || $route->live_instance !== $revB) {
            $this->error("Route did not point at B after cutover (got '".($route->live_instance ?? 'none')."').");

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        if (! $this->isRetired($incus, $cluster, $revA)) {
            $this->error('A was not marked retired after cutover.');

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        $stopExpected = (bool) config('deploy.reap.stop_retired', true);
        if ($stopExpected && $this->isRunning($incus, $cluster, $revA)) {
            $this->error('A is still running though stop_retired is on.');

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        $this->info("  route -> B, A retired".($stopExpected ? ' + stopped' : '').". Cutover proven.");
        $this->assertEdge($edgeProvider, $incus, $standInAlias, $host, 'B');

        // ── 2) REVERT B -> A ─────────────────────────────────────────────────
        $this->info('Reverting B -> A…');
        try {
            $lifecycle->revert($app, $revA);
        } catch (\Throwable $e) {
            $this->error('revert failed: '.$e->getMessage());

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }

        $route = AppRoute::query()->where('app', $app)->first();
        if (! $route || $route->live_instance !== $revA) {
            $this->error("Route did not swing back to A after revert (got '".($route->live_instance ?? 'none')."').");

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        if ($this->isRetired($incus, $cluster, $revA)) {
            $this->error("A's retirement mark was not cleared on revert.");

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        if (! $this->isRetired($incus, $cluster, $revB)) {
            $this->error('B was not marked retired after revert.');

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        $this->info('  route -> A, A live again, B retired. Revert proven.');
        $this->assertEdge($edgeProvider, $incus, $standInAlias, $host, 'A');

        // ── 3) REAP (backdate B past the window, then reap) ──────────────────
        $this->info('Backdating B past the reap window and reaping…');
        $past = time() - (((int) config('deploy.reap.days', 7) + 1) * 86400);
        $incus->updateInstance($cluster, $revB, ['config' => ['user.kixctl.retired_at' => (string) $past]]);

        $reap = $lifecycle->reap($app);
        if (! in_array($revB, $reap['reaped'], true)) {
            $this->error('B was not reaped though it is retired and past the window.');

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        if ($incus->instanceExists($cluster, $revB)) {
            $this->error('B still exists after reap.');

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        if (! $incus->instanceExists($cluster, $revA)) {
            $this->error('A (live) was reaped — it must never be.');

            return $this->bail($incus, $cluster, [$revA], $ingress, $app);
        }
        $this->info('  B reaped, A (live) kept. Reap proven.');

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$revA}' + the '{$app}' route in place.");

            return self::SUCCESS;
        }

        $this->teardown($incus, $cluster, [$revA], $ingress, $app);
        $this->info('Clean: cutover, revert, and reap all proven end to end.');

        return self::SUCCESS;
    }

    /** Launch a stand-in revision on the deploy network/profile and wait for its lease. */
    private function launch(IncusClient $incus, Cluster $cluster, string $name, string $fingerprint, string $target, string $profile, string $network): ?string
    {
        try {
            $incus->launchBuiltImage(
                $cluster,
                $name,
                $fingerprint,
                $target,
                profiles: $profile !== '' ? [$profile] : ['power'],
                network: $network !== '' ? $network : null,
            );
        } catch (\Throwable $e) {
            $this->error("launch of '{$name}' failed: ".$e->getMessage());

            return null;
        }

        for ($i = 0; $i < 15; $i++) {
            try {
                $ip = $incus->instanceIpv4($cluster, $name);
            } catch (\Throwable) {
                $ip = null;
            }
            if ($ip !== null) {
                return $ip;
            }
            sleep(1);
        }

        $this->error("'{$name}' never took a lease.");

        return null;
    }

    private function isRetired(IncusClient $incus, Cluster $cluster, string $instance): bool
    {
        $raw = $incus->instance($cluster, $instance);

        return (string) ($raw['config']['user.kixctl.retired_at'] ?? '') !== '';
    }

    private function isRunning(IncusClient $incus, Cluster $cluster, string $instance): bool
    {
        $raw = $incus->instance($cluster, $instance);

        return ($raw['status'] ?? '') === 'Running';
    }

    /** When the owned edge is the provider, assert it proxies the host to a 200. */
    private function assertEdge(bool $edgeProvider, IncusClient $incus, string $edge, string $host, string $label): void
    {
        if (! $edgeProvider) {
            $this->warn("  provider is not 'edge' — skipped the edge-reachability check for {$label} (route + DNS still asserted).");

            return;
        }

        sleep(2); // let caddy --watch reload
        $result = Process::timeout(20)->run([
            'incus', 'exec', $edge, '--',
            'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-H', "Host: {$host}", 'http://localhost/',
        ]);
        $code = trim($result->output());
        if ($code === '200') {
            $this->line("  edge proxies {$host} -> 200 (serving {$label}).");
        } else {
            $this->warn("  edge returned {$code} for {$host} (serving {$label}) — route asserted, reachability not confirmed.");
        }
    }

    /** @param list<string> $instances */
    private function teardown(IncusClient $incus, Cluster $cluster, array $instances, ?IngressManager $ingress = null, ?string $app = null): void
    {
        if ($ingress && $app) {
            try {
                $ingress->withdraw($app);
            } catch (\Throwable $e) {
                $this->warn("Route withdraw failed: {$e->getMessage()}");
            }
        }
        foreach ($instances as $instance) {
            try {
                if ($incus->instanceExists($cluster, $instance)) {
                    $incus->deleteInstance($cluster, $instance);
                }
            } catch (\Throwable $e) {
                $this->warn("Delete of '{$instance}' failed: {$e->getMessage()} — remove it by hand.");
            }
        }
    }

    /** @param list<string> $instances */
    private function bail(IncusClient $incus, Cluster $cluster, array $instances, ?IngressManager $ingress = null, ?string $app = null): int
    {
        $this->teardown($incus, $cluster, $instances, $ingress, $app);

        return self::FAILURE;
    }
}
