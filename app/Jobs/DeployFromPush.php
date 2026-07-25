<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deploy a pushed commit: build a NixOS image from the repo, import it over the
 * Incus REST API, launch it on a chosen target, then register a Caddy route.
 *
 * P3-2 slice A (this commit) records the TRIGGER only — it logs the structured
 * deploy intent so the whole webhook path can be proven end to end (push ->
 * signature verified -> queued -> job ran) with no host-touching work yet. Later
 * slices replace the body with: nix build (build host) -> IncusClient::importImage
 * -> IncusClient::launchBuiltImage on a chosen target -> Caddy route.
 *
 * Runs on the existing long-timeout `incus` lane (supervisor-incus), so no
 * Horizon config change is needed for this slice.
 */
class DeployFromPush implements ShouldQueue
{
    use Queueable;

    /** A deploy is never safe to auto-retry blindly; one attempt only. */
    public int $tries = 1;

    /** Headroom for a full build + image pull in later slices; harmless now. */
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
        // Slice A: prove the trigger. The next slice replaces this with the real
        // build -> importImage -> launchBuiltImage -> Caddy pipeline.
        Log::info('deploy.triggered', [
            'repository' => $this->repository,
            'clone_url' => $this->cloneUrl,
            'branch' => $this->branch,
            'commit' => $this->commit,
        ]);
    }
}
