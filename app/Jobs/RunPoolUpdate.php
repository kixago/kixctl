<?php

namespace App\Jobs;

use App\Events\DeployProgress;
use App\Models\Pool;
use App\Models\User;
use App\Services\Deploy\DeploymentManager;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Promote a whole pool in one operator action (P3-7 Slice 4, D28): loop the pool's
 * members and run the SAME per-app cutover the Updates tab already runs, once per
 * member. There is deliberately NO new promotion mechanism here — the batch is a
 * thin, honest wrapper around DeploymentManager::cutover(); if a single cutover is
 * correct, the batch is correct.
 *
 * The one hard part is that a batch is NOT a loop with one green/red at the end. An
 * immutable partial batch is a VALID state — promote six, one fails, the five are
 * legitimately live and stay there — so this job:
 *
 *   - attempts each member independently and CONTINUES past a failure,
 *   - treats a member with nothing ready as a `skipped` no-op, not an error
 *     (idempotent per member — running twice promotes nothing the second time),
 *   - collects a per-member result and reports a real tally, never a lone status.
 *
 * Progress rides the existing public `deploys` channel (DeployProgress), not a
 * token toast: a promoted member emits the same `published` event a single cutover
 * would, so the Updates tab's deployWatch flips that member's card live and re-pulls
 * — twenty edge/DNS cutovers have no business blocking a web request. A closing
 * Filament notification carries the tally to the operator who pressed Update all.
 *
 * No dedup lock is needed: a double-dispatch is safe because the second run finds
 * every member already current and skips it. Lives on the `incus` lane, one attempt
 * (a batch of route swings is not safe to blind-retry), with headroom for a large
 * pool's worth of start-wait-render cutovers.
 */
class RunPoolUpdate implements ShouldQueue
{
    use Queueable;

    /** A batch of route swings is not safe to auto-retry blindly; one attempt only. */
    public int $tries = 1;

    /** Headroom for a large pool: each member may start a stopped revision, wait for a lease, and re-render the edge. */
    public int $timeout = 1800;

    public function __construct(
        public ?int $userId,
        public int $poolId,
    ) {
        $this->onQueue('incus');
    }

    public function handle(DeploymentManager $deployment): void
    {
        $pool = Pool::query()->find($this->poolId);
        if (! $pool) {
            $this->notify(__('pools.promote.pool_gone'), '', 'danger');

            return;
        }

        $members = $pool->repositories()->orderBy('slug')->get();

        /** @var list<string> $promoted */
        $promoted = [];
        /** @var list<string> $failed  ("slug: reason" lines) */
        $failed = [];
        $skipped = 0;

        foreach ($members as $member) {
            $slug = (string) $member->slug;
            $ready = null;

            try {
                $ready = $deployment->revisions($slug)['update_ready'];

                if ($ready === null) {
                    // Nothing landed to promote — already current. A no-op, by design.
                    $skipped++;

                    continue;
                }

                $deployment->cutover($slug, $ready);
                $promoted[] = $slug;

                $short = (string) Str::of($ready)->afterLast('-');
                $this->announce($slug, 'published', $ready, $short,
                    __('updates.deploy.published', ['app' => $slug, 'sha' => $short]));
            } catch (\Throwable $e) {
                $reason = Str::limit($e->getMessage(), 160);
                $failed[] = "{$slug}: {$reason}";

                Log::error('pool.promote.member_failed', [
                    'pool' => $pool->name,
                    'app' => $slug,
                    'instance' => $ready,
                    'exception' => $e,
                ]);

                // Key the failure row by the ready instance when we got that far,
                // else by the member slug so the tab still surfaces the failure.
                $this->announce($slug, 'failed', $ready ?? $slug,
                    $ready !== null ? (string) Str::of($ready)->afterLast('-') : null,
                    __('pools.promote.member_failed', ['app' => $slug, 'reason' => $reason]));
            }
        }

        Log::info('pool.promote', [
            'pool' => $pool->name,
            'members' => $members->count(),
            'promoted' => $promoted,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        $this->report($pool, $members->count(), $promoted, $skipped, $failed);
    }

    /**
     * Broadcast one member's terminal result on the shared `deploys` channel so the
     * Updates tab narrates it and re-pulls — the same rail a push deploy uses.
     * Best-effort: a broadcast failure must never fail the promotion it describes.
     */
    private function announce(string $app, string $phase, string $instance, ?string $sha, string $message): void
    {
        try {
            event(new DeployProgress(
                app: $app,
                phase: $phase,
                instance: $instance,
                sha: $sha,
                message: $message,
            ));
        } catch (\Throwable $e) {
            Log::warning('pool.promote.announce_failed', ['app' => $app, 'phase' => $phase, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Send the operator who pressed Update all the honest tally: clean run,
     * everything-already-current, or a partial batch that names its failures.
     */
    private function report(Pool $pool, int $total, array $promoted, int $skipped, array $failed): void
    {
        // Nothing to do: a race (someone promoted between render and click) or an
        // all-current pool. Say so plainly rather than leaving a silent no-op.
        if ($promoted === [] && $failed === []) {
            $this->notify(
                __('pools.promote.summary_title', ['pool' => $pool->label]),
                __('pools.promote.summary_none', ['pool' => $pool->label]),
                'success',
            );

            return;
        }

        $lines = [__('pools.promote.summary', ['promoted' => count($promoted), 'total' => $total])];
        if ($skipped > 0) {
            $lines[] = __('pools.promote.summary_skipped', ['count' => $skipped]);
        }
        if ($failed !== []) {
            $lines[] = __('pools.promote.summary_failed', ['list' => implode('; ', $failed)]);
        }

        // Partial success is a warning (finished, but look); a wholly-failed batch
        // is danger; a clean run is success.
        $level = $failed === [] ? 'success' : ($promoted === [] ? 'danger' : 'warning');

        $title = $failed === []
            ? __('pools.promote.summary_title', ['pool' => $pool->label])
            : __('pools.promote.summary_title_failed', ['pool' => $pool->label]);

        $this->notify($title, implode(' ', $lines), $level);
    }

    /** Broadcast a Filament toast to the initiating user, if we know who that is. */
    private function notify(string $title, string $body, string $level): void
    {
        if (! $this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $n = Notification::make()->title($title);
        if ($body !== '') {
            $n->body($body);
        }

        match ($level) {
            'danger' => $n->danger(),
            'warning' => $n->warning(),
            default => $n->success(),
        };

        $n->broadcast($user);
    }
}
