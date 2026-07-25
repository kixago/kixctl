<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deploy (push-to-deploy) — P3-2
    |--------------------------------------------------------------------------
    |
    | Configuration for the git-push -> build -> import/launch -> route pipeline.
    | This file grows one slice at a time; slice A only needs the Forgejo webhook
    | secret. Later slices add: the build host + build user, the base flake path,
    | default target selection, and the Caddy route zone.
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

];
