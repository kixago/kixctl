<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Deploy a pushed commit: build a NixOS image from the repo, import it over the
 * Incus REST API, launch it on a chosen target, then register a Caddy route.
 *
 * P3-2 slice B builds the image. It hands the pushed repo (pinned to the exact
 * commit) to the `kixctl-build` subsystem — the one legitimately host-touching
 * step — invoked with fixed, validated arguments via an argument array, so no
 * shell is involved. On success the two image tarballs (metadata + rootfs) are
 * on disk; their paths are logged as `deploy.built`.
 *
 * Slice C reads those two paths with IncusClient::importImage(...) and launches
 * on a chosen target; slice D writes the Caddy route. Runs on the existing
 * long-timeout `incus` lane (supervisor-incus).
 */
class DeployFromPush implements ShouldQueue
{
    use Queueable;

    /** A deploy is never safe to auto-retry blindly; one attempt only. */
    public int $tries = 1;

    /** Headroom for a full nixpkgs eval + build; tune via the queue if needed. */
    public int $timeout = 1800;

    public function __construct(
        public string $repository,
        public string $cloneUrl,
        public string $branch,
        public string $commit,
    ) {
        $this->onQueue('incus');
    }

    public function handle(): void
    {
        // Pin the build to the exact pushed commit: git+<clone url>?rev=<sha>.
        // Nix fetches the repo at that revision hermetically — no local clone,
        // no working tree, no ambiguity about "what main is right now".
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

        // Slice B ends here: the image is built and its two tarballs are on disk.
        Log::info('deploy.built', [
            'repository' => $this->repository,
            'commit' => $this->commit,
            'metadata' => $paths['metadata'],
            'rootfs' => $paths['rootfs'],
        ]);
    }
}
