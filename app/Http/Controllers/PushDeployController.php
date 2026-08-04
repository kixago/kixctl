<?php

namespace App\Http\Controllers;

use App\Jobs\DeployFromPush;
use App\Models\Repository;
use App\Services\Deploy\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives a push webhook from any supported git host and, for a push to a
 * registered repository's tracked branch, queues a deploy (P3-6). This replaces
 * the single-repo Forgejo receiver: the repository is looked up by the payload's
 * full_name and verified against THAT repository's own secret, so one endpoint
 * serves every registered repo instead of one global secret serving one repo.
 *
 * The route host segment selects the signature dialect only:
 *   forgejo | gitea | codeberg — raw-hex HMAC in X-Forgejo/X-Gitea-Signature
 *   github                     — "sha256=" HMAC in X-Hub-Signature-256
 *
 * Full paths: POST /api/deploy/forgejo (unchanged), /api/deploy/github, etc.
 *
 * The webhook is only the low-latency trigger. The repository row is the source
 * of truth for HOW to fetch and build — its stored clone URL (SSH-first), branch
 * and build attribute — so a webhook push and a polled push produce the identical
 * DeployFromPush. A repo with no secret simply relies on the poller; an
 * unregistered repo or a bad signature is refused without touching the cluster.
 *
 * Authenticated solely by the per-repo signature (no user, no session, no CSRF —
 * it lives on the stateless `api` middleware group).
 */
class PushDeployController extends Controller
{
    public function __invoke(Request $request, string $host): JsonResponse
    {
        $host = strtolower($host);

        $data = $request->json()->all();
        $fullName = (string) data_get($data, 'repository.full_name', '');
        if ($fullName === '') {
            return response()->json(['message' => 'No repository in payload.'], 202);
        }

        $repo = Repository::query()
            ->where('full_name', $fullName)
            ->where('is_active', true)
            ->first();

        if (! $repo) {
            // Not registered: acknowledge and ignore. Not an error, and not an
            // open door — nothing is dispatched.
            return response()->json(['message' => 'Repository is not registered with kixctl.'], 202);
        }

        $secret = (string) ($repo->webhook_secret ?? '');
        if ($secret === '') {
            // No per-repo secret set: this repo is deployed by the poller, not by
            // webhook. Refuse rather than accept an unauthenticated push.
            return response()->json(['message' => 'Webhook is not enabled for this repository.'], 202);
        }

        if (! $this->signatureValid($request, $host, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($this->event($request, $host) !== 'push') {
            return response()->json(['message' => 'Ignoring non-push event.'], 202);
        }

        $ref = (string) data_get($data, 'ref', '');
        $commit = (string) data_get($data, 'after', '');
        $branch = str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : $ref;

        // Deploy only the repo's tracked branch: its configured default_branch,
        // else the branch the payload names as default. Anything else is ignored.
        $tracked = $repo->default_branch !== null && $repo->default_branch !== ''
            ? $repo->default_branch
            : (string) data_get($data, 'repository.default_branch', '');

        if ($tracked !== '' && $branch !== $tracked) {
            return response()->json([
                'message' => "Ignoring push to ‘{$branch}’ (deploys track ‘{$tracked}’).",
            ], 202);
        }

        if ($commit === '') {
            return response()->json(['message' => 'Push carried no commit to build.'], 202);
        }

        DeployFromPush::dispatch(
            repository: $repo->full_name,
            cloneUrl: $repo->clone_url,
            branch: $branch,
            commit: $commit,
            buildAttr: $repo->buildAttr(),
            slug: $repo->slug,
        );

        return response()->json(['message' => 'Deploy queued.'], 202);
    }

    /** Verify the payload signature in the dialect the host segment selects. */
    private function signatureValid(Request $request, string $host, string $secret): bool
    {
        $body = $request->getContent();

        if ($host === 'github') {
            return WebhookSignature::validGithub($body, $request->header('X-Hub-Signature-256'), $secret);
        }

        // forgejo / gitea / codeberg all sign the raw body as lowercase hex.
        $signature = $request->header('X-Forgejo-Signature')
            ?? $request->header('X-Gitea-Signature');

        return WebhookSignature::valid($body, $signature, $secret);
    }

    /** The event name the host reports (only 'push' triggers a deploy). */
    private function event(Request $request, string $host): ?string
    {
        if ($host === 'github') {
            return $request->header('X-GitHub-Event');
        }

        return $request->header('X-Forgejo-Event') ?? $request->header('X-Gitea-Event');
    }
}
