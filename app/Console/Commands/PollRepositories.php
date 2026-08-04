<?php

namespace App\Console\Commands;

use App\Jobs\DeployFromPush;
use App\Models\Repository;
use App\Services\Deploy\GitRemote;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The host-agnostic commit poller (P3-6, decision D27). For each active,
 * poll-enabled repository it reads the tip commit of the tracked branch with
 * GitRemote (`git ls-remote`) and dispatches a deploy when that commit is not
 * already a running revision. Works against Forgejo, GitHub, Codeberg or anything
 * else with no per-host plumbing — it is the "watch a repo over time" baseline
 * that makes push-to-deploy real for repos kixctl can't be webhooked from.
 *
 * The dispatch is identical to the webhook's: both resolve clone URL, branch and
 * build attribute from the repository row and hand them to DeployFromPush. A
 * webhook is simply the low-latency trigger for a repo that has one; the poller
 * is the floor under all of them.
 *
 * Runs from the scheduler every minute; each repo is only actually polled once
 * its own interval has elapsed. Guarded against re-dispatching an in-flight or
 * previously-failed build by last_seen_sha, so a broken build isn't retried every
 * tick — an operator retries deliberately (the Deploy now button, or --force).
 *
 *   php artisan kixctl:poll-repositories
 *   php artisan kixctl:poll-repositories --repo=kixago/demo-app --force
 */
class PollRepositories extends Command
{
    protected $signature = 'kixctl:poll-repositories
        {--repo= : Poll only this repository (owner/repo), ignoring its interval}
        {--force : Dispatch even if the tip commit equals last_seen_sha (retry a held build)}';

    protected $description = 'Poll registered repositories and deploy new commits (host-agnostic, ls-remote based)';

    public function handle(GitRemote $git, IncusClient $incus, ClusterRegistry $registry): int
    {
        $only = (string) $this->option('repo');
        $force = (bool) $this->option('force');

        // The scheduled run honours the global switch; an explicit --repo/--force
        // is an operator action and always runs.
        if ($only === '' && ! $force && ! (bool) config('deploy.poll.enabled', true)) {
            $this->info('Repository polling is disabled (config deploy.poll.enabled).');

            return self::SUCCESS;
        }

        $query = Repository::query()->where('is_active', true);
        if ($only !== '') {
            $query->where('full_name', $only);
        } else {
            $query->where('poll_enabled', true);
        }

        $repos = $query->get();
        if ($repos->isEmpty()) {
            $this->info($only !== '' ? "No active repository named ‘{$only}’." : 'No repositories to poll.');

            return self::SUCCESS;
        }

        $cluster = collect($registry->all())->first();
        if (! $cluster) {
            $this->error('No cluster registered — cannot check which revisions are already deployed.');

            return self::FAILURE;
        }

        // One live instance list per run; the deployed-SHA set is filtered per repo.
        $instanceNames = collect($incus->instances($cluster))
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->all();

        $dispatched = 0;

        foreach ($repos as $repo) {
            if (! $force && $only === '' && ! $repo->dueForPoll()) {
                continue;
            }

            $dispatched += $this->pollOne($git, $cluster, $repo, $instanceNames, $force) ? 1 : 0;
        }

        $this->info("Polled {$repos->count()} repository(ies); dispatched {$dispatched} deploy(s).");

        return self::SUCCESS;
    }

    /**
     * Poll a single repository. Returns true when it dispatched a deploy. Records
     * the outcome on the row either way (last_polled_at, last_seen_sha or
     * last_poll_error) so the UI shows a live, honest state.
     */
    private function pollOne(GitRemote $git, Cluster $cluster, Repository $repo, array $instanceNames, bool $force): bool
    {
        try {
            $head = $git->resolve($repo);
        } catch (\Throwable $e) {
            Log::warning('poll.resolve_failed', ['repo' => $repo->full_name, 'error' => $e->getMessage()]);
            $repo->forceFill([
                'last_polled_at' => now(),
                'last_poll_error' => $e->getMessage(),
            ])->save();
            $this->warn("  {$repo->full_name}: {$e->getMessage()}");

            return false;
        }

        $sha = $head['sha'];
        $short = substr($sha, 0, 7);

        // Learn the default branch on first successful poll, if it was left blank.
        if (blank($repo->default_branch) && $head['branch'] !== '') {
            $repo->default_branch = $head['branch'];
        }

        // Already a running revision → nothing to do; record the sighting.
        if (in_array($repo->slug.'-'.$short, $instanceNames, true)) {
            $repo->forceFill([
                'last_polled_at' => now(),
                'last_seen_sha' => $sha,
                'last_poll_error' => null,
            ])->save();

            return false;
        }

        // Not deployed, but we already tried this exact commit (build in flight or
        // failed) → hold, unless the operator forces a retry.
        if (! $force && $repo->last_seen_sha === $sha) {
            $repo->forceFill(['last_polled_at' => now()])->save();

            return false;
        }

        DeployFromPush::dispatch(
            repository: $repo->full_name,
            cloneUrl: $repo->clone_url,
            branch: $head['branch'] !== '' ? $head['branch'] : (string) $repo->default_branch,
            commit: $sha,
            buildAttr: $repo->buildAttr(),
            slug: $repo->slug,
        );

        Log::info('poll.dispatched', ['repo' => $repo->full_name, 'commit' => $sha, 'slug' => $repo->slug]);

        $repo->forceFill([
            'last_polled_at' => now(),
            'last_seen_sha' => $sha,
            'last_poll_error' => null,
        ])->save();

        $this->info("  {$repo->full_name}: deploying {$short}.");

        return true;
    }
}
