<?php

namespace App\Jobs;

use App\Events\DeployProgress;
use App\Models\AppRoute;
use App\Models\DeployAppConfig;
use App\Services\Deploy\DeploymentManager;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Deploy a pushed commit: build a NixOS image from the repo, import it over the
 * Incus REST API, launch it as an immutable per-revision instance on the owned
 * bridge, then hand routing to the deploy lifecycle.
 *
 * Slice B builds the image (the one host-touching step, via the kixctl-build
 * subsystem). Slice C imports that image and launches it as an IMMUTABLE,
 * per-revision instance named "<repo>-<sha7>" on a chosen cluster member — a
 * fresh unit each push, never a mutation of a running one. It holds no durable
 * state; the job only ever ADDS a revision, never removes one.
 *
 * The revision lands on the kixctl-OWNED network + profile (kixbr0 + kix) by
 * default — internal-by-default, reachable only through kixctl's own edge, never
 * sprayed onto the operator's LAN (overridable in config/deploy.php).
 *
 * Routing is the lifecycle's call, not the job's: DeploymentManager::landOrPublish
 * publishes the FIRST revision of an app (so it is reachable by name at once), but
 * when a different revision is already live it LANDS the new one ALONGSIDE — the
 * route is left untouched and the revision surfaces as "update ready" for the
 * operator to promote with a cutover. A push therefore never silently swings live
 * traffic onto unproven code; promotion, revert, and reap are explicit operator
 * actions (or, later, a policy). Runs on the long-timeout `incus` lane.
 *
 * Progress is announced on the public `deploys` channel (DeployProgress) at build
 * start and at every terminal point — the Updates tab did not initiate this build,
 * so this broadcast is the only way an open tab learns a revision landed without a
 * manual refresh.
 */
class DeployFromPush implements ShouldQueue
{
    use Queueable;

    /** A deploy is never safe to auto-retry blindly; one attempt only. */
    public int $tries = 1;

    /** Headroom for a full nixpkgs eval + build + image import. */
    public int $timeout = 1800;

    public function __construct(
        public string $repository,
        public string $cloneUrl,
        public string $branch,
        public string $commit,
        public string $buildAttr = '',
        public string $slug = '',
        public bool $forceRebuild = false,
    ) {
        $this->onQueue('incus');
    }

    public function handle(IncusClient $incus, ClusterRegistry $registry, DeploymentManager $deployment): void
    {
        $short = substr($this->commit, 0, 7);
        $this->announce('building', __('updates.deploy.building', ['app' => $this->appKey(), 'sha' => $short]));

        // ── Build ────────────────────────────────────────────────────────────
        // Pin the build to the exact pushed commit: git+<clone url>?rev=<sha>.
        // Nix fetches the repo at that revision hermetically — no local clone.
        $flakeRef = 'git+'.$this->cloneUrl.'?rev='.$this->commit;

        // Per-repo build attribute (P3-6). The single global DEPLOY_BUILD_ATTR is
        // retired: the repository row carries its own attr, and the install
        // default only applies when that row leaves it blank.
        $attr = $this->buildAttr !== '' ? $this->buildAttr : (string) config('deploy.build.attr', 'default');

        $result = Process::timeout($this->timeout)->run([
            base_path('scripts/kixctl-build'),
            '--flake', $flakeRef,
            '--attr', $attr,
            '--kind', 'container',
        ]);

        if (! $result->successful()) {
            Log::error('deploy.build_failed', [
                'repository' => $this->repository,
                'commit' => $this->commit,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);
            $this->announce('failed', __('updates.deploy.build_failed', ['app' => $this->appKey(), 'sha' => $short]));

            return;
        }

        $paths = json_decode(trim($result->output()), true);
        if (! is_array($paths) || empty($paths['metadata']) || empty($paths['rootfs'])) {
            Log::error('deploy.build_bad_output', [
                'repository' => $this->repository,
                'commit' => $this->commit,
                'stdout' => $result->output(),
            ]);
            $this->announce('failed', __('updates.deploy.no_image', ['app' => $this->appKey(), 'sha' => $short]));

            return;
        }

        Log::info('deploy.built', [
            'repository' => $this->repository,
            'commit' => $this->commit,
            'metadata' => $paths['metadata'],
            'rootfs' => $paths['rootfs'],
        ]);

        // ── Resolve the target cluster + member ──────────────────────────────
        $clusterKey = (string) config('deploy.launch.cluster', '');
        $cluster = $clusterKey !== '' ? $registry->find($clusterKey) : null;
        $cluster ??= collect($registry->all())->first();

        if (! $cluster) {
            Log::error('deploy.no_cluster', [
                'repository' => $this->repository,
                'commit' => $this->commit,
            ]);
            $this->announce('failed', __('updates.deploy.no_cluster'));

            return;
        }

        $target = (string) config('deploy.launch.target', 'powerhouse');
        $name = $this->instanceName();

        // The kixctl-owned network + profile the revision lands on (kixbr0 + kix
        // by default): internal-by-default, reachable only through kixctl's edge.
        $profile = (string) config('deploy.launch.profile', 'kix');
        $network = (string) config('deploy.launch.network', 'kixbr0');

        // ── Injected config: per-app env carried into every revision ─────────
        // Declared once in kixctl, delivered into each revision as credstore
        // files (/etc/credstore/<KEY>, 0400 root-only) pushed before the
        // container starts. systemd's ImportCredential=* picks them up as system
        // credentials and the kixctl-base env-bridge exposes each as an env var,
        // so a fresh revision comes up already knowing where its state lives.
        // Unlike the old systemd.credential.* instance key, the value is not
        // visible in `incus config show`.
        $credentials = DeployAppConfig::query()
            ->where('app', $this->repository)
            ->get()
            ->mapWithKeys(fn (DeployAppConfig $c) => [$c->key => (string) $c->value])
            ->all();

        // Log only the KEYS, never the values.
        Log::info('deploy.config', [
            'instance' => $name,
            'keys' => array_keys($credentials),
        ]);

        // ── Import the built image (idempotent per revision via its alias) ────
        try {
            $fingerprint = $incus->importImage(
                $cluster,
                $paths['metadata'],
                $paths['rootfs'],
                alias: $name,
            );
        } catch (\Throwable $e) {
            Log::error('deploy.import_failed', [
                'repository' => $this->repository,
                'commit' => $this->commit,
                'error' => $e->getMessage(),
            ]);
            $this->announce('failed', __('updates.deploy.import_failed', ['app' => $this->appKey(), 'sha' => $short]));

            return;
        }

        // ── Content-level no-op: skip an identical rebuild ───────────────────
        // The commit SHA always differs, but a docs/comment-only change — or a
        // revert to the live tree — builds a byte-identical image whose
        // fingerprint the live revision already runs. There is nothing to
        // deploy: the running box already IS this artifact. Announce it and
        // stop, rather than launching an identical twin and raising a spurious
        // "update ready". A commit that changes the build graph yields a
        // different fingerprint and falls through to launch + land as normal.
        // force_rebuild (per repo, off by default) opts back into a distinct
        // revision for every push regardless of content. Read straight from the
        // live instance's volatile.base_image — the fingerprint Incus recorded
        // when it created it — so there is no kixctl-side ledger to drift.
        if (! $this->forceRebuild) {
            $live = optional(AppRoute::query()->where('app', $this->appKey())->first())->live_instance;

            if ($live && $live !== $name
                && $incus->instanceExists($cluster, $live)
                && ($incus->instance($cluster, $live)['config']['volatile.base_image'] ?? '') === $fingerprint
            ) {
                Log::info('deploy.unchanged', [
                    'repository' => $this->repository,
                    'commit' => $this->commit,
                    'live' => $live,
                    'fingerprint' => $fingerprint,
                ]);
                $this->announce('unchanged', __('updates.deploy.unchanged', ['app' => $this->appKey(), 'sha' => $short]));

                return;
            }
        }

        // ── Launch the immutable revision on the target member ───────────────
        // If this exact revision is already running (a re-deploy of the same
        // commit), that IS the desired state — leave it, don't recreate it.
        try {
            if ($incus->instanceExists($cluster, $name)) {
                Log::info('deploy.launch_exists', [
                    'instance' => $name,
                    'target' => $target,
                ]);
            } else {
                $incus->launchBuiltImage(
                    $cluster,
                    $name,
                    $fingerprint,
                    $target,
                    profiles: $profile !== '' ? [$profile] : ['power'],
                    credentials: $credentials,
                    network: $network !== '' ? $network : null,
                );
            }
        } catch (\Throwable $e) {
            Log::error('deploy.launch_failed', [
                'repository' => $this->repository,
                'commit' => $this->commit,
                'instance' => $name,
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            $this->announce('failed', __('updates.deploy.launch_failed', ['app' => $this->appKey(), 'sha' => $short]));

            return;
        }

        // Best-effort IP — the DHCP lease can take a couple of seconds to appear.
        $ip = null;
        for ($i = 0; $i < 10 && $ip === null; $i++) {
            try {
                $ip = $incus->instanceIpv4($cluster, $name);
            } catch (\Throwable) {
                // state not ready yet; try again
            }
            if ($ip === null) {
                sleep(1);
            }
        }

        Log::info('deploy.launched', [
            'repository' => $this->repository,
            'commit' => $this->commit,
            'instance' => $name,
            'target' => $target,
            'fingerprint' => $fingerprint,
            'ip' => $ip,
            'network' => $network,
        ]);

        // ── Route: publish if first, otherwise land alongside ────────────────
        // The lifecycle decides: the FIRST revision of an app is published so it
        // is reachable by name immediately; a later revision LANDS ALONGSIDE the
        // running one (route untouched) and surfaces as "update ready" for the
        // operator to promote with a cutover. A push never silently swings live
        // traffic. Skipped if the lease never appeared: the revision is up but
        // unrouted, and the operator can act on it from the Updates surface once
        // it settles.
        if ($ip === null) {
            Log::warning('deploy.no_ip_skipping_route', ['instance' => $name]);
            $this->announce('failed', __('updates.deploy.no_ip', ['app' => $this->appKey(), 'sha' => $short]));

            return;
        }

        try {
            $outcome = $deployment->landOrPublish(
                $this->appKey(),
                $name,
                $ip,
                (int) config('ingress.app_port', 8080),
            );

            Log::info('deploy.route_outcome', [
                'app' => $this->appKey(),
                'instance' => $name,
                'ip' => $ip,
                'outcome' => $outcome, // 'published' (went live) | 'alongside' (update ready)
            ]);

            // Terminal announce, phase mirrors the routing outcome so the tab can
            // colour it: a first revision goes live; a later one is ready to promote.
            $this->announce(
                $outcome === 'published' ? 'published' : 'landed',
                $outcome === 'published'
                    ? __('updates.deploy.published', ['app' => $this->appKey(), 'sha' => $short])
                    : __('updates.deploy.landed', ['app' => $this->appKey(), 'sha' => $short]),
            );
        } catch (\Throwable $e) {
            // A routing failure must not fail the deploy — the revision is up;
            // routing can be re-asserted from the GUI. Log loudly and move on.
            Log::error('deploy.route_failed', [
                'app' => $this->appKey(),
                'instance' => $name,
                'error' => $e->getMessage(),
            ]);
            $this->announce('failed', __('updates.deploy.route_failed', ['app' => $this->appKey(), 'sha' => $short]));
        }
    }

    /**
     * Broadcast one deploy-progress step on the public `deploys` channel. The
     * Updates tab (which did not start this build) subscribes and narrates it,
     * then refreshes on a terminal phase. Best-effort: a broadcast failure must
     * never break a deploy, so it is swallowed and logged.
     */
    private function announce(string $phase, string $message): void
    {
        try {
            event(new DeployProgress(
                app: $this->appKey(),
                phase: $phase,
                instance: $this->instanceName(),
                sha: substr($this->commit, 0, 7),
                message: $message,
            ));
        } catch (\Throwable $e) {
            Log::warning('deploy.announce_failed', ['phase' => $phase, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The stable app key for routing: the repo leaf, sanitized, WITHOUT the sha.
     * Constant across revisions so <app>.<zone> never moves; instanceName() adds
     * the sha for the per-revision instance.
     */
    private function appKey(): string
    {
        // The repo's registered slug is the stable app name — the DNS host
        // (<slug>.<zone>) and the per-revision instance namespace (<slug>-<sha7>).
        // Unique per repo, so two repos sharing a leaf can't collide. Deriving
        // from the repo path is only a fallback for a dispatch with no slug.
        if ($this->slug !== '') {
            return $this->slug;
        }

        $leaf = (string) str($this->repository)->afterLast('/')->slug();

        return $leaf !== '' ? $leaf : 'app';
    }

    /**
     * The immutable per-revision instance name: "<repo leaf>-<sha7>", sanitized
     * to a valid Incus instance name (lowercase letters, digits, hyphens; <= 63).
     */
    private function instanceName(): string
    {
        $sha = substr($this->commit, 0, 7);

        return substr($this->appKey().'-'.$sha, 0, 63);
    }
}
