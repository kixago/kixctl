<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Services\Deploy\GitRemote;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for the P3-6 commit reader — proves GitRemote can reach a
 * repo and resolve its tip commit, and shows exactly what the poller would
 * decide, WITHOUT registering anything or triggering a build.
 *
 * Two modes:
 *   - a registered repo:   php artisan kixctl:repo-probe --full-name=kixago/demo-app
 *   - an ad-hoc URL test:  php artisan kixctl:repo-probe --url=ssh://git@git.lan.kixago.com:2222/kixago/demo-app.git
 *
 * It never mutates: it reads the remote (D27), lists the cluster's instances, and
 * reports whether a deploy WOULD fire for the resolved SHA — the same decision
 * PollRepositories makes, surfaced for inspection.
 */
class RepoProbe extends Command
{
    protected $signature = 'kixctl:repo-probe
        {--full-name= : A registered repository to probe (owner/repo)}
        {--url= : An ad-hoc clone URL to test connectivity for, without registering}
        {--branch= : Branch for --url mode (blank resolves the remote HEAD)}';

    protected $description = 'Prove the git-host reader: resolve a repo’s tip commit and show what the poller would do (isolation test)';

    public function handle(GitRemote $git, IncusClient $incus, ClusterRegistry $registry): int
    {
        $repo = $this->resolveRepo();
        if (! $repo) {
            return self::FAILURE;
        }

        $this->info("Repository : {$repo->full_name}  (slug ‘{$repo->slug}’, ".($repo->isSsh() ? 'SSH' : 'HTTPS').")");
        $this->line("Clone URL  : {$repo->clone_url}");
        $this->line('Branch     : '.($repo->default_branch !== null && $repo->default_branch !== '' ? $repo->default_branch : '(resolve HEAD)'));

        // 1) READ — resolve the tip commit over ls-remote (the D27 read).
        try {
            $head = $git->resolve($repo);
        } catch (\Throwable $e) {
            $this->error('ls-remote failed: '.$e->getMessage());
            $this->newLine();
            $this->warn('For an ssh:// URL, confirm the host key is known and the key authenticates:');
            $this->line('  GIT_SSH_COMMAND="ssh -o BatchMode=yes" git ls-remote '.$repo->clone_url.' HEAD');

            return self::FAILURE;
        }

        $sha = $head['sha'];
        $short = substr($sha, 0, 7);
        $this->info("  HEAD of ‘{$head['branch']}’ = {$sha}  ({$short}). Remote read proven.");

        // 2) DECIDE — show what the poller would conclude from live cluster state.
        $cluster = collect($registry->all())->first();
        if (! $cluster) {
            $this->warn('No cluster registered — cannot show the deploy decision (read step still proven).');

            return self::SUCCESS;
        }

        $deployed = $this->deployedShas($incus, $cluster, $repo->slug);
        $this->line('Deployed revisions: '.($deployed !== [] ? implode(', ', $deployed) : '(none yet)'));

        if (in_array($short, $deployed, true)) {
            $this->info("  {$repo->slug}-{$short} already exists — a poll would do nothing. Correct.");
        } elseif (! $this->option('url') && $repo->last_seen_sha === $sha) {
            $this->warn("  Not deployed, but last_seen_sha already equals {$short} — a poll would hold (build in flight or previously failed). Use --force on the poller to retry.");
        } else {
            $this->info("  {$repo->slug}-{$short} is NOT deployed — a poll WOULD dispatch a deploy. Correct.");
        }

        return self::SUCCESS;
    }

    /** Load a registered repo, or build an unsaved one from --url for a bare test. */
    private function resolveRepo(): ?Repository
    {
        $fullName = (string) $this->option('full-name');
        $url = (string) $this->option('url');

        if ($fullName !== '') {
            $repo = Repository::query()->where('full_name', $fullName)->first();
            if (! $repo) {
                $this->error("No registered repository named ‘{$fullName}’.");

                return null;
            }

            return $repo;
        }

        if ($url !== '') {
            // Unsaved stand-in purely to exercise GitRemote against a URL.
            $repo = new Repository([
                'full_name' => (string) (parse_url($url, PHP_URL_PATH) ?: $url),
                'clone_url' => $url,
                'default_branch' => (string) $this->option('branch') ?: null,
            ]);
            $repo->slug = Repository::deriveSlug($repo->full_name);
            $repo->host = Repository::deriveHost($url);

            return $repo;
        }

        $this->error('Pass --full-name=<owner/repo> for a registered repo, or --url=<clone url> for an ad-hoc test.');

        return null;
    }

    /**
     * The set of already-deployed short SHAs for a slug, read live from the
     * cluster — instances named "<slug>-<sha7>".
     *
     * @return list<string>
     */
    private function deployedShas(IncusClient $incus, $cluster, string $slug): array
    {
        $prefix = $slug.'-';
        $out = [];

        foreach ($incus->instances($cluster) as $i) {
            $name = (string) ($i['name'] ?? '');
            if (str_starts_with($name, $prefix)
                && preg_match('/^'.preg_quote($slug, '/').'-([0-9a-f]{7})$/', $name, $m) === 1) {
                $out[] = $m[1];
            }
        }

        return $out;
    }
}
