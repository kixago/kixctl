<?php

namespace App\Services\Deploy;

use App\Models\Repository;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads the current commit of a repository's tracked branch with `git ls-remote`
 * (P3-6, decision D27). This is the SECOND — and only other — host-touching read
 * kixctl performs, beside `nix build`: the control plane still talks to the
 * cluster solely over the Incus REST API, and this stays fenced exactly like the
 * builder — one fixed program invoked as a NO-SHELL argument array, never a shell
 * string, so nothing from a repo name or URL can be interpreted as a command.
 *
 * It is read-only: it looks at a remote ref and returns a SHA. It cannot change
 * anything on the host or the cluster.
 *
 * SSH-first. The clone URL carries the auth story; a private ssh:// repo
 * authenticates through the access the host already has (D25), so no key or token
 * is stored here. GIT_SSH_COMMAND forces BatchMode so a missing/blocked key fails
 * fast with a clear message instead of hanging on a prompt, and
 * GIT_TERMINAL_PROMPT=0 does the same for an https URL with no credentials.
 *
 * Host-agnostic on purpose: ls-remote speaks the same protocol to Forgejo,
 * GitHub, Codeberg and anything else, so there is no per-host API adapter to
 * write or maintain — the point of P3-6's poller.
 */
final class GitRemote
{
    /**
     * Resolve the tip commit of the repo's tracked branch.
     *
     * With a known branch, ask for exactly that ref. With none, resolve HEAD's
     * symref in a single call and report the branch it points at, so the caller
     * can record it.
     *
     * @return array{sha:string, branch:string}
     */
    public function resolve(Repository $repo): array
    {
        $url = trim((string) $repo->clone_url);
        if ($url === '') {
            throw new RuntimeException('The repository has no clone URL.');
        }

        $branch = trim((string) $repo->default_branch);

        if ($branch !== '') {
            $ref = 'refs/heads/'.$branch;
            $out = $this->exec(['git', 'ls-remote', $url, $ref]);
            $sha = $this->shaForRef($out, $ref);

            if ($sha === null) {
                throw new RuntimeException("Branch ‘{$branch}’ was not found on the remote.");
            }

            return ['sha' => $sha, 'branch' => $branch];
        }

        // Unknown branch: --symref returns both the symbolic ref and HEAD's SHA.
        $out = $this->exec(['git', 'ls-remote', '--symref', $url, 'HEAD']);
        $sha = $this->shaForRef($out, 'HEAD');

        if ($sha === null) {
            throw new RuntimeException('Could not resolve HEAD on the remote.');
        }

        return ['sha' => $sha, 'branch' => (string) $this->branchFromSymref($out)];
    }

    /** Run the fixed argv with a hardened, non-interactive git environment. */
    private function exec(array $argv): string
    {
        $result = Process::env([
            'GIT_SSH_COMMAND' => (string) config('deploy.poll.ssh_command', 'ssh -o BatchMode=yes -o ConnectTimeout=10'),
            'GIT_TERMINAL_PROMPT' => '0',
        ])->timeout((int) config('deploy.poll.timeout', 30))->run($argv);

        if (! $result->successful()) {
            $err = trim($result->errorOutput());
            if ($err === '') {
                $err = trim($result->output());
            }

            throw new RuntimeException($this->redact($err !== '' ? $err : 'git ls-remote failed.'));
        }

        return $result->output();
    }

    /**
     * Pull the 40-hex SHA that ls-remote paired with $ref. Each line is
     * "<value>\t<name>"; a symref call emits a "ref: …" line whose value is not a
     * SHA, which the hex guard skips.
     */
    private function shaForRef(string $out, string $ref): ?string
    {
        foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            if ($parts === false || count($parts) !== 2) {
                continue;
            }

            [$value, $name] = $parts;
            if ($name === $ref && preg_match('/^[0-9a-f]{40}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /** The branch HEAD points at, from a "ref: refs/heads/<branch>\tHEAD" line. */
    private function branchFromSymref(string $out): ?string
    {
        foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $line) {
            if (preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD$#', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Redact any embedded credential before an error reaches a log or the UI.
     * SSH-first URLs rarely carry one, but an https URL might hold a token; a
     * "user:pass@host" is collapsed to "***@host" so it is never surfaced.
     */
    private function redact(string $text): string
    {
        return (string) preg_replace('#://[^/@\s]+@#', '://***@', $text);
    }
}
