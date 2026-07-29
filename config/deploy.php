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

];
