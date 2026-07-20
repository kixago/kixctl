<?php

$resolve = static function (?string $path): ?string {
    if (! $path) {
        return null;
    }

    // Absolute paths pass through; relative paths resolve against the app
    // root so the cert/key are found regardless of the process working
    // directory (artisan serve, a Horizon worker, or a systemd unit).
    return str_starts_with($path, '/') ? $path : base_path($path);
};

return [
    // 'socket' = local admin socket (dev, on a cluster member — this is you now).
    // 'https'  = remote cluster over a least-privilege client cert (real installs).
    'driver' => env('INCUS_DRIVER', 'socket'),
    'label' => env('INCUS_LABEL', 'My Cluster'),

    'socket' => env('INCUS_SOCKET', '/var/lib/incus/unix.socket'),

    // Used only when driver=https (the installer generates these).
    'url' => env('INCUS_URL', 'https://192.168.2.8:8443'),
    'client_cert' => $resolve(env('INCUS_CLIENT_CERT')),
    'client_key' => $resolve(env('INCUS_CLIENT_KEY')),
    'verify' => env('INCUS_VERIFY', false),
];
