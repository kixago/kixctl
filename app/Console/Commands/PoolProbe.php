<?php

namespace App\Console\Commands;

use App\Models\Pool;
use App\Models\Repository;
use App\Services\Deploy\DeploymentManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for P3-7 pooled promotion (D28, Slice 3) — proves what a
 * pool-level "Update all" WOULD do before Slice 4 builds the surface that does
 * it, WITHOUT dispatching a single cutover.
 *
 *   php artisan kixctl:pool-probe --pool=web
 *
 * The probe reads only. For each member of the pool it asks the SAME engine the
 * Updates tab asks — DeploymentManager::revisions() — and reports which members
 * have a revision "ready to promote" (a landed-but-unpromoted build, the tab's
 * `update_ready`) and, per member, exactly which cutover Update-all would fire.
 * Nothing is queued, nothing on the cluster is touched.
 *
 * It leans on the one load-bearing mapping in the deploy path: a Repository's
 * `slug` IS the app key — the DNS host (<slug>.<zone>), the per-revision instance
 * (<slug>-<sha7>), and the string DeployFromPush::appKey() routes under. So a
 * member's promotion state is simply revisions($member->slug).
 *
 * Per-member honesty is deliberate and previews the Slice 4 invariant: a batch is
 * a set of independent per-member decisions, never an all-or-nothing. One member
 * whose cluster read fails is reported and stepped over, exactly as the real
 * Update-all will continue past a failed member rather than abort the batch.
 */
class PoolProbe extends Command
{
    protected $signature = 'kixctl:pool-probe
        {--pool= : The pool to probe, by name or label}';

    protected $description = 'Prove pooled promotion: show which members are ready and what Update-all would do (isolation test)';

    public function handle(DeploymentManager $deployment): int
    {
        $pool = $this->resolvePool();
        if (! $pool) {
            return self::FAILURE;
        }

        $members = $pool->repositories()->orderBy('slug')->get();

        $this->info("Pool : {$pool->label}  (name ‘{$pool->name}’, {$members->count()} member".($members->count() === 1 ? '' : 's').')');

        if ($members->isEmpty()) {
            $this->warn('  No members — an Update-all on this pool would be a no-op. Assign apps to it on the Repositories tab.');

            return self::SUCCESS;
        }

        // Pad the slug column so a batch readout stays a tidy table, not a ragged
        // list — the whole point of pools is the operator running many at once.
        $width = (int) $members->max(fn (Repository $r) => strlen((string) $r->slug));

        $ready = $current = $undeployed = $errored = 0;

        foreach ($members as $member) {
            $slug = (string) $member->slug;
            $label = str_pad($slug, $width);

            try {
                $state = $deployment->revisions($slug);
            } catch (\Throwable $e) {
                $errored++;
                $this->error("  {$label}  ERROR   {$e->getMessage()}");

                continue;
            }

            $liveShort = $this->short($state['live']);
            $readyShort = $this->short($state['update_ready']);

            if ($state['update_ready'] !== null) {
                $ready++;
                $from = $liveShort !== null ? "{$slug}-{$liveShort}" : '(no live route yet)';
                $this->info("  {$label}  READY   cut over  {$from} → {$slug}-{$readyShort}");
            } elseif ($state['revisions'] === []) {
                $undeployed++;
                $this->line("  {$label}  —       not yet deployed (no revisions on the cluster)");
            } else {
                $current++;
                $this->line("  {$label}  ok      already current".($liveShort !== null ? " (live {$slug}-{$liveShort})" : ''));
            }
        }

        $this->newLine();
        $this->info(
            "Update-all would promote {$ready} of {$members->count()} member".($members->count() === 1 ? '' : 's').
            " — {$ready} ready, {$current} current, {$undeployed} not yet deployed".
            ($errored > 0 ? ", {$errored} unreadable" : '').'.'
        );

        // Only a cluster that couldn't be read for ANY member is a probe failure;
        // a member simply having nothing to promote is a correct, healthy result.
        return ($errored === $members->count()) ? self::FAILURE : self::SUCCESS;
    }

    /** Load the pool named (or labelled) by --pool, or list the choices and fail. */
    private function resolvePool(): ?Pool
    {
        $selector = trim((string) $this->option('pool'));

        if ($selector !== '') {
            $pool = Pool::query()
                ->where('name', $selector)
                ->orWhere('label', $selector)
                ->first();

            if ($pool) {
                return $pool;
            }

            $this->error("No pool matches ‘{$selector}’ by name or label.");
        } else {
            $this->error('Pass --pool=<name> to choose a pool to probe.');
        }

        $pools = Pool::query()->orderBy('name')->get();
        if ($pools->isEmpty()) {
            $this->line('There are no pools yet — create one on the Pools tab first.');

            return null;
        }

        $this->newLine();
        $this->line('Available pools:');
        foreach ($pools as $pool) {
            $this->line("  {$pool->name}  ({$pool->label})");
        }

        return null;
    }

    /** The short SHA of a "<slug>-<sha7>" instance name, or null when absent. */
    private function short(?string $instance): ?string
    {
        if ($instance === null || $instance === '') {
            return null;
        }

        return (string) str($instance)->afterLast('-');
    }
}
