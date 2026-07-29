<?php

namespace App\Jobs;

use App\Models\DeployAppConfig;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\IngressManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Deploy a pushed commit: build a NixOS image from the repo, import it over the
 * Incus REST API, launch it as an immutable per-revision instance on the owned
 * bridge, then publish its route through the selected ingress provider.
 *
 * Slice B builds the image (the one host-touching step, via the kixctl-build
 * subsystem). Slice C imports that image and launches it as an IMMUTABLE,
 * per-revision instance named "<repo>-<sha7>" on a chosen cluster member — a
 * fresh unit each push, never a mutation of a running one. It holds no durable
 * state (that boundary is a later, deliberate slice); revert and cutover live in
 * later slices, so this job only ever ADDS a revision, never removes one.
 *
 * The revision lands on the kixctl-OWNED network + profile (kixbr0 + kix) by
 * default — internal-by-default, reachable only through kixctl's own edge, never
 * sprayed onto the operator's LAN (overridable in config/deploy.php).
 *
 * Slice D publishes the route via IngressManager: `edge` points <app>.<zone> at
 * kixctl's own Caddy (which reverse-proxies to the revision) and writes CoreDNS;
 * `managed` writes DNS to the revision; `manual` records only. The app key is the
 * stable repo leaf, so a new push re-points the existing route — no churn. Runs
 * on the existing long-timeout `incus` lane (supervisor-incus).
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
    ) {
        $this->onQueue('incus');
    }

    public function handle(IncusClient $incus, ClusterRegistry $registry, IngressManager $ingress): void
    {
        // ── Build ────────────────────────────────────────────────────────────
        // Pin the build to the exact pushed commit: git+<clone url>?rev=<sha>.
        // Nix fetches the repo at that revision hermetically — no local clone.
        $flakeRef = 'git+'.$this->cloneUrl.'?rev='.$this->commit;
        $attr = (string) config('deploy.build.attr', 'default');

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

            return;
        }

        $paths = json_decode(trim($result->output()), true);
        if (! is_array($paths) || empty($paths['metadata']) || empty($paths['rootfs'])) {
            Log::error('deploy.build_bad_output', [
                'repository' => $this->repository,
                'commit' => $this->commit,
                'stdout' => $result->output(),
            ]);

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

            return;
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

        // ── Publish the route (Slice D) ──────────────────────────────────────
        // Point <app>.<zone> at this revision through whatever provider the
        // operator selected: `edge` ensures kixctl's own Caddy + CoreDNS and
        // writes both (host -> caddy -> app); `managed` writes DNS; `manual`
        // records only. The app KEY is the stable repo leaf (constant across
        // revisions), so a new push just re-points the existing route at the new
        // revision — no route churn, cutover/revert stay a later slice. Skipped
        // if the lease never appeared: the instance is up but unrouted, and the
        // operator can Publish from the Records tab once it settles.
        if ($ip === null) {
            Log::warning('deploy.no_ip_skipping_publish', ['instance' => $name]);

            return;
        }

        try {
            $ingress->publish(
                $this->appKey(),
                $name,
                $ip,
                (int) config('ingress.app_port', 8080),
            );

            Log::info('deploy.published', [
                'app' => $this->appKey(),
                'instance' => $name,
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            // A publish failure must not fail the deploy — the revision is live;
            // routing can be re-asserted from the GUI. Log loudly and move on.
            Log::error('deploy.publish_failed', [
                'app' => $this->appKey(),
                'instance' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The stable app key for routing: the repo leaf, sanitized, WITHOUT the sha.
     * Constant across revisions so <app>.<zone> never moves; instanceName() adds
     * the sha for the per-revision instance.
     */
    private function appKey(): string
    {
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
