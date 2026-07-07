<?php

return [
    // 'socket' = local admin socket (dev, on a cluster member — this is you now).
    // 'https'  = remote cluster over a least-privilege client cert (real installs).
    'driver' => env('INCUS_DRIVER', 'socket'),
    'label' => env('INCUS_LABEL', 'My Cluster'),

    'socket' => env('INCUS_SOCKET', '/var/lib/incus/unix.socket'),

    // Used only when driver=https (added later; the installer generates these).
    'url'         => env('INCUS_URL', 'https://192.168.2.8:8443'),
    'client_cert' => env('INCUS_CLIENT_CERT'),
    'client_key'  => env('INCUS_CLIENT_KEY'),
    'verify'      => env('INCUS_VERIFY', false),
];
