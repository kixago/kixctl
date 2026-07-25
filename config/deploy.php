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

];
