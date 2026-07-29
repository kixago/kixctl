<?php

namespace App\Console\Commands;

use App\Models\AppRoute;
use App\Services\Deploy\DeploymentManager;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\IngressManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for the DeployFromPush land-alongside rewire — proves the one
 * behavioural change a push now makes, without a git build.
 *
 * It stands up two throwaway "revisions" of a fake app from the owned caddy image
 * and drives DeploymentManager::landOrPublish exactly as DeployFromPush now does:
 *
 *   1. FIRST revision  — nothing live yet, so landOrPublish returns 'published'
 *      and the route points at A. (A push of a brand-new app goes live at once.)
 *   2. SECOND revision — A is already live, so landOrPublish returns 'alongside',
 *      leaves the route on A untouched, and the new revision B shows up as the
 *      "update ready" candidate. (A push to a running app never swings traffic.)
 *
 * Read-only-ish: it only creates the two throwaways and one app_routes row, then
 * tears both down. It never cuts over, so nothing is retired or stopped.
 *
 *   php artisan kixctl:landing-probe
 *   php artisan kixctl:landing-probe --keep
 */
class LandingProbe extends Command
{
    protected $signature = 'kixctl:landing-probe
        {--app=landprobe : Throwaway app key}
        {--keep : Leave the revisions + route in place after proving}';

    protected $description = 'Prove the deploy land-alongside decision: first revision publishes, later ones land alongside (isolation test)';

    public function handle(IncusClient $incus, ClusterRegistry $registry, IngressManager $ingress, DeploymentManager $deployment): int
    {
        $app = (string) $this->option('app');
        $revA = "{$app}-aaaaaa1";
        $revB = "{$app}-bbbbbb2";

        $cluster = collect($registry->all())->first();
        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        // Refuse to run if a real route already owns this app key, so the probe
        // can never disturb a live app that happens to share the name.
        if (AppRoute::query()->where('app', $app)->exists()) {
            $this->error("An app_routes row for '{$app}' already exists — choose a different --app.");

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

        // ── 1) First revision publishes ─────────────────────────────────────
        $this->info("Launching first revision A ('{$revA}') on {$network} + {$profile}…");
        $ipA = $this->launch($incus, $cluster, $revA, $fingerprint, $target, $profile, $network);
        if ($ipA === null) {
            return $this->bail($incus, $cluster, [$revA], $ingress, $app);
        }

        $outcome = $deployment->landOrPublish($app, $revA, $ipA, 80);
        $route = AppRoute::query()->where('app', $app)->first();
        if ($outcome !== 'published' || ! $route || $route->live_instance !== $revA) {
            $this->error("First revision did not publish (outcome='{$outcome}', live='".($route->live_instance ?? 'none')."').");

            return $this->bail($incus, $cluster, [$revA], $ingress, $app);
        }
        $this->info("  outcome=published, route -> A. First revision goes live. Proven.");

        // ── 2) Second revision lands alongside ──────────────────────────────
        $this->info("Launching second revision B ('{$revB}') while A is live…");
        $ipB = $this->launch($incus, $cluster, $revB, $fingerprint, $target, $profile, $network);
        if ($ipB === null) {
            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }

        $outcome = $deployment->landOrPublish($app, $revB, $ipB, 80);
        $route = AppRoute::query()->where('app', $app)->first();
        if ($outcome !== 'alongside') {
            $this->error("Second revision did not land alongside (outcome='{$outcome}').");

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        if (! $route || $route->live_instance !== $revA) {
            $this->error("Second revision stole the route (live='".($route->live_instance ?? 'none')."', expected A).");

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        $state = $deployment->revisions($app);
        if (($state['update_ready'] ?? null) !== $revB) {
            $this->error("B is not surfaced as update_ready (got '".($state['update_ready'] ?? 'none')."').");

            return $this->bail($incus, $cluster, [$revA, $revB], $ingress, $app);
        }
        $this->info('  outcome=alongside, route still -> A, B is update_ready. Land-alongside proven.');

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$revA}' + '{$revB}' + the '{$app}' route in place.");

            return self::SUCCESS;
        }

        $this->teardown($incus, $cluster, [$revA, $revB], $ingress, $app);
        $this->info('Clean: first revision publishes, later revisions land alongside without swinging traffic.');

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

    /** @param list<string> $instances */
    private function teardown(IncusClient $incus, Cluster $cluster, array $instances, IngressManager $ingress, string $app): void
    {
        try {
            $ingress->withdraw($app);
        } catch (\Throwable $e) {
            $this->warn("Route withdraw failed: {$e->getMessage()}");
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
    private function bail(IncusClient $incus, Cluster $cluster, array $instances, IngressManager $ingress, string $app): int
    {
        $this->teardown($incus, $cluster, $instances, $ingress, $app);

        return self::FAILURE;
    }
}
