<?php

namespace App\Http\Controllers;

use App\Jobs\DeployFromPush;
use App\Services\Deploy\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Forgejo push webhooks and, for a push to the repository's default
 * branch, queues a deploy.
 *
 * P3-2 slice A proves the TRIGGER only: verify the HMAC signature, confirm it is
 * a push event, parse the push into a structured intent, and hand it to the
 * queue. The nix build, importImage / launchBuiltImage on a chosen target, and
 * the Caddy route all arrive in later slices — this controller does not touch
 * the host or the cluster.
 *
 * Authenticated solely by the Forgejo signature (no logged-in user, no session,
 * no CSRF — it lives on the stateless `api` middleware group).
 */
class ForgejoWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('deploy.forgejo.webhook_secret', '');
        if ($secret === '') {
            // Not configured. Refuse rather than silently accept unauthenticated
            // pushes — an unconfigured deploy endpoint must not be an open door.
            return response()->json(['message' => 'Deploy webhook is not configured.'], 503);
        }

        $signature = $request->header('X-Forgejo-Signature')
            ?? $request->header('X-Gitea-Signature');

        if (! WebhookSignature::valid($request->getContent(), $signature, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Only push events trigger a deploy; anything else is acknowledged and ignored.
        $event = $request->header('X-Forgejo-Event') ?? $request->header('X-Gitea-Event');
        if ($event !== 'push') {
            return response()->json(['message' => "Ignoring '{$event}' event."], 202);
        }

        $data = $request->json()->all();
        $ref = (string) data_get($data, 'ref', '');                     // refs/heads/<branch>
        $after = (string) data_get($data, 'after', '');                 // commit to build
        $cloneUrl = (string) data_get($data, 'repository.clone_url', '');
        $fullName = (string) data_get($data, 'repository.full_name', '');
        $defaultBranch = (string) data_get($data, 'repository.default_branch', '');

        $branch = str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : $ref;

        // Deploy policy (slice A): build only pushes to the repo's default branch.
        // The payload names its own default branch, so this needs no configuration.
        // Pushes to any other branch are acknowledged and ignored.
        if ($defaultBranch !== '' && $branch !== $defaultBranch) {
            return response()->json([
                'message' => "Ignoring push to '{$branch}' (deploys are '{$defaultBranch}' only).",
            ], 202);
        }

        DeployFromPush::dispatch(
            repository: $fullName,
            cloneUrl: $cloneUrl,
            branch: $branch,
            commit: $after,
        );

        return response()->json(['message' => 'Deploy queued.'], 202);
    }
}
