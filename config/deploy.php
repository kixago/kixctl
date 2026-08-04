<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deploy (push-to-deploy) — P3-2
    |--------------------------------------------------------------------------
    |
    | Configuration for the git-push -> build -> import/launch -> route pipeline.
    | This file grows one slice at a time. Later slices add: the target host
    | selection (slice C) and the Caddy route zone (slice D).
    |
    */

    'forgejo' => [
        // Shared secret set on the Forgejo repository webhook. Forgejo signs the
        // raw request body with HMAC-SHA256 and sends the hex digest in the
        // X-Forgejo-Signature header; the receiver verifies against this value.
        // Keep it in .env for now, and move it into sops alongside the license
        // signing key before any public release.
        'webhook_secret' => env('FORGEJO_WEBHOOK_SECRET'),
    ],

    'build' => [
        // The nixosConfigurations attribute the builder targets. Contract: a
        // deployable repo's flake exposes nixosConfigurations.<attr>. Default
        // "default"; override per-install via .env while iterating (e.g. point
        // it at the demo flake's "node-demo" without editing the flake).
        'attr' => env('DEPLOY_BUILD_ATTR', 'default'),
    ],

    'launch' => [
        // Cluster key to deploy into. Empty = the first active cluster (matches
        // the create-instance form's default). Set once there is more than one.
        'cluster' => env('DEPLOY_LAUNCH_CLUSTER', ''),

        // Cluster member the instance is placed on (mandatory on a cluster).
        // First cut: everything lands on powerhouse; a per-deploy target picker
        // is a later slice.
        'target' => env('DEPLOY_LAUNCH_TARGET', 'powerhouse'),

        // The kixctl-OWNED network + profile every deployed revision lands on.
        // The product default is the owned bridge (kixbr0) via the kix profile:
        // a deployed app is internal-by-default and reachable ONLY through
        // kixctl's own edge (CoreDNS + Caddy), never sprayed onto the operator's
        // LAN. kixbr0 has NAT, so the app still reaches operator services on br0
        // (e.g. its database) outbound. Override to '' / 'power' to place deploys
        // on the operator's network instead (old behaviour).
        'network' => env('DEPLOY_LAUNCH_NETWORK', 'kixbr0'),
        'profile' => env('DEPLOY_LAUNCH_PROFILE', 'kix'),
    ],

    'poll' => [
        // The host-agnostic commit poller (P3-6, decision D27). Every active,
        // poll-enabled repository is checked with `git ls-remote` on a schedule;
        // a commit that isn't already a running revision is deployed. This is the
        // baseline that makes GitHub/Codeberg/Forgejo work with zero webhook
        // plumbing — webhooks stay the low-latency optimization on top.
        'enabled' => (bool) env('DEPLOY_POLL_ENABLED', true),

        // Non-interactive git environment for the ls-remote read. BatchMode makes
        // a missing or blocked SSH key fail fast with a clear error instead of
        // hanging on a prompt; keep it strict so an unknown host key is a visible
        // failure, not a silent auto-trust (rely on the host's known_hosts, which
        // an SSH-first operator already has for repos they push to).
        'ssh_command' => env('DEPLOY_POLL_SSH_COMMAND', 'ssh -o BatchMode=yes -o ConnectTimeout=10'),

        // Hard cap on a single ls-remote call, in seconds.
        'timeout' => (int) env('DEPLOY_POLL_TIMEOUT', 30),
    ],

    'reap' => [
        // How long a superseded (cut-over-away) revision is kept before it
        // becomes eligible for reaping. Within this window a revert is a one-click
        // swing back to a still-present, intact container — no rebuild, no reverse
        // migration (decisions.md D6). The revision's retirement time is recorded
        // on the instance itself (user.kixctl.retired_at), so this window is the
        // only knob; there is no separate revision ledger to keep in sync.
        'days' => (int) env('DEPLOY_REAP_DAYS', 7),

        // When kixctl cuts over to a new revision, stop the outgoing one. It stays
        // on disk, intact, so a revert is start + re-point (a few seconds) — the
        // resource-honest default at fleet scale, where dozens of retired
        // revisions running idle would waste real CPU/RAM. Set false to leave
        // retired revisions running for a zero-start revert.
        'stop_retired' => (bool) env('DEPLOY_REAP_STOP_RETIRED', true),
    ],

];
