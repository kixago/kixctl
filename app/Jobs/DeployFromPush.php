<?php

namespace App\Jobs;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Deploy a pushed commit: build a NixOS image from the repo, import it over the
 * Incus REST API, launch it on a chosen target, then register a Caddy route.
 *
 * Slice B builds the image (the one host-touching step, via the kixctl-build
 * subsystem). Slice C imports that image and launches it as an IMMUTABLE,
 * per-revision instance named "<repo>-<sha7>" on a chosen cluster member — a
 * fresh unit each push, never a mutation of a running one. It holds no durable
 * state (that boundary is a later, deliberate slice); revert and cutover live in
 * later slices, so this job only ever ADDS a revision, never removes one.
 *
 * Slice D writes the Caddy route to the live revision. Runs on the existing
 * long-timeout `incus` lane (supervisor-incus).
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

    public function handle(IncusClient $incus, ClusterRegistry $registry): void
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
                $incus->launchBuiltImage($cluster, $name, $fingerprint, $target);
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
        ]);
    }

    /**
     * The immutable per-revision instance name: "<repo leaf>-<sha7>", sanitized
     * to a valid Incus instance name (lowercase letters, digits, hyphens; <= 63).
     */
    private function instanceName(): string
    {
        $leaf = (string) str($this->repository)->afterLast('/')->slug();
        $leaf = $leaf !== '' ? $leaf : 'app';
        $sha = substr($this->commit, 0, 7);

        return substr($leaf.'-'.$sha, 0, 63);
    }
}
