<?php

return [
    /*
    | Ed25519 public key (base64, 32 bytes raw) used to verify license
    | files OFFLINE — no network call, ever. The production key gets
    | embedded here as the default at release time; until then the env
    | var carries the dev keypair. Empty = no license can validate, the
    | app simply runs the free tier.
    */
    'public_key' => env('KIXCTL_LICENSE_PUBLIC_KEY', ''),

    /*
    | Where the operator drops their license file. One logical line:
    | base64(payload).base64(signature). Missing file = free tier.
    */
    'path' => env('KIXCTL_LICENSE', storage_path('app/kixctl.license')),

    /*
    | Endpoint rows (clusters table) the free tier may hold. Every verb
    | works on every one of them — free is capped by scale, never
    | capability. Tunable 1 vs 2 per monetization.md; default 2.
    */
    'free_cluster_cap' => (int) env('KIXCTL_FREE_CLUSTER_CAP', 2),
];
