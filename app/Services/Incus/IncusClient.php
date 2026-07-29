<?php

namespace App\Services\Incus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class IncusClient
{
    private array $topologyCache = [];

    protected function materializeCredential(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A filesystem path (config behavior) — pass through untouched.
        if (! str_starts_with(ltrim($value), '-----BEGIN')) {
            return $value;
        }

        static $cache = [];
        $hash = hash('sha256', $value);
        if (isset($cache[$hash]) && is_file($cache[$hash])) {
            return $cache[$hash];
        }

        $dir = (is_dir('/dev/shm') && is_writable('/dev/shm'))
            ? '/dev/shm/kixctl-incus'
            : sys_get_temp_dir().'/kixctl-incus';

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir.'/'.$hash.'.pem';
        if (! is_file($path)) {
            file_put_contents($path, $value);
            chmod($path, 0600);
        }

        return $cache[$hash] = $path;
    }

    private function topology(Cluster $cluster): array
    {
        if (isset($this->topologyCache[$cluster->key])) {
            return $this->topologyCache[$cluster->key];
        }

        $info = $this->get($cluster, '/1.0/cluster');
        $enabled = (bool) ($info['enabled'] ?? false);

        $name = $info['server_name'] ?? '';
        if ($name === '') {
            $server = $this->get($cluster, '/1.0');
            $name = $server['environment']['server_name'] ?? $cluster->key;
        }

        return $this->topologyCache[$cluster->key] = ['enabled' => $enabled, 'name' => $name];
    }

    private function resolveLocation(Cluster $cluster, ?string $location): string
    {
        if ($location === null || $location === '' || $location === 'none') {
            return $this->topology($cluster)['name'];
        }

        return $location;
    }

    public function serverInfo(Cluster $cluster): array
    {
        $s = $this->get($cluster, '/1.0');

        return [
            'server_version' => $s['environment']['server_version'] ?? null,
            'os_name' => $s['environment']['os_name'] ?? null,
        ];
    }

    public function members(Cluster $cluster): array
    {
        $topology = $this->topology($cluster);
        if (! $topology['enabled']) {
            return [[
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'name' => $topology['name'],
                'status' => 'Online',
                'message' => 'Standalone server',
                'url' => $cluster->connection['url'] ?? '',
                'roles' => [],
            ]];
        }

        return collect($this->get($cluster, '/1.0/cluster/members', ['recursion' => 1]))
            ->map(fn ($m) => [
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'name' => $m['server_name'],
                'status' => $m['status'],
                'message' => $m['message'] ?? '',
                'url' => $m['url'] ?? '',
                'roles' => $m['roles'] ?? [],
            ])
            ->all();
    }

    public function memberState(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        $data = $this->get($cluster, "/1.0/cluster/members/{$encoded}/state");
        $sys = $data['sysinfo'] ?? [];
        $totalRam = $sys['total_ram'] ?? 0;
        $freeRam = $sys['free_ram'] ?? 0;
        $buffered = $sys['buffered_ram'] ?? 0;
        $usedRam = max(0, $totalRam - $freeRam - $buffered);

        $pool = collect($data['storage_pools'] ?? [])
            ->map(fn ($p, $poolName) => [
                'name' => $poolName,
                'total' => $p['space']['total'] ?? 0,
                'used' => $p['space']['used'] ?? 0,
            ])
            ->sortByDesc('total')
            ->first();

        return [
            'ram_total' => $totalRam,
            'ram_used' => $usedRam,
            'ram_pct' => $totalRam > 0 ? round($usedRam / $totalRam * 100, 1) : 0,
            'load' => $sys['load_averages'] ?? [0, 0, 0],
            'processes' => $sys['processes'] ?? 0,
            'pool_name' => $pool['name'] ?? null,
            'pool_total' => $pool['total'] ?? 0,
            'pool_used' => $pool['used'] ?? 0,
            'pool_pct' => ($pool['total'] ?? 0) > 0 ? round($pool['used'] / $pool['total'] * 100, 1) : 0,
        ];
    }

    public function instances(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/instances', ['recursion' => 2]))
            ->map(fn ($i) => [
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'name' => $i['name'],
                'type' => $i['type'],
                'status' => $i['status'],
                'node' => $this->resolveLocation($cluster, $i['location'] ?? null),
                'ipv4' => $this->primaryIpv4($i['state'] ?? null),
                'profiles' => $i['profiles'] ?? [],
                'last_used_at' => $i['last_used_at'] ?? null,
            ])
            ->sortBy('node')
            ->values()
            ->all();
    }

    public function profiles(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/profiles', ['recursion' => 1]))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    public function profilesFull(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/profiles', ['recursion' => 1]))
            ->map(fn ($p) => [
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'name' => $p['name'],
                'description' => $p['description'] ?? '',
                'used_by' => count($p['used_by'] ?? []),
                'devices' => array_keys($p['devices'] ?? []),
            ])
            ->values()
            ->all();
    }

    public function profile(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        $p = $this->get($cluster, "/1.0/profiles/{$encoded}");

        return [
            'name' => $p['name'] ?? $name,
            'description' => $p['description'] ?? '',
            'config' => $p['config'] ?? [],
            'devices' => $p['devices'] ?? [],
            'used_by' => $p['used_by'] ?? [],
        ];
    }

    public function storagePools(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/storage-pools', ['recursion' => 1]))
            ->map(fn ($p) => [
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'name' => $p['name'],
                'driver' => $p['driver'] ?? '',
                'status' => $p['status'] ?? '',
                'used_by' => count($p['used_by'] ?? []),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function storageVolumes(Cluster $cluster, string $pool): array
    {
        $encodedPool = rawurlencode($pool);

        return collect($this->get($cluster, "/1.0/storage-pools/{$encodedPool}/volumes", ['recursion' => 1]))
            ->map(fn ($v) => [
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'pool' => $pool,
                'name' => $v['name'],
                'type' => $v['type'] ?? '',
                'content_type' => $v['content_type'] ?? '',
                'node' => $this->resolveLocation($cluster, $v['location'] ?? null),
                'used_by' => count($v['used_by'] ?? []),
            ])
            ->sortBy([['node', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    public function networks(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/networks', ['recursion' => 1]))
            ->map(fn ($n) => [
                'cluster' => $cluster->key,
                'cluster_label' => $cluster->label,
                'name' => $n['name'],
                'type' => $n['type'] ?? '',
                'managed' => (bool) ($n['managed'] ?? false),
                'status' => $n['status'] ?? '',
                'description' => $n['description'] ?? '',
                'ipv4_nat' => ($n['config']['ipv4.nat'] ?? '') === 'true',
                'ipv4_dhcp' => ($n['config']['ipv4.dhcp'] ?? '') === 'true',
                'ipv4_address' => $n['config']['ipv4.address'] ?? '',
                'ipv6_nat' => ($n['config']['ipv6.nat'] ?? '') === 'true',
                'used_by' => count($n['used_by'] ?? []),
            ])
            ->sortByDesc('managed')
            ->values()
            ->all();
    }

    public function network(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        $n = $this->get($cluster, "/1.0/networks/{$encoded}");

        return [
            'name' => $n['name'] ?? $name,
            'type' => $n['type'] ?? '',
            'managed' => (bool) ($n['managed'] ?? false),
            'status' => $n['status'] ?? '',
            'description' => $n['description'] ?? '',
            'config' => $n['config'] ?? [],
            'used_by' => count($n['used_by'] ?? []),
        ];
    }

    /** True if a network of this name already exists on the cluster (managed or not). */
    public function networkExists(Cluster $cluster, string $name): bool
    {
        $response = $this->request($cluster)->get('/1.0/networks/'.rawurlencode($name));
        if ($response->status() === 404) {
            return false;
        }
        $response->throw();

        return true;
    }

    /** True if a profile of this name already exists on the cluster. */
    public function profileExists(Cluster $cluster, string $name): bool
    {
        $response = $this->request($cluster)->get('/1.0/profiles/'.rawurlencode($name));
        if ($response->status() === 404) {
            return false;
        }
        $response->throw();

        return true;
    }

    public function createInstance(Cluster $cluster, array $payload, ?string $target = null, int $timeout = 300): void
    {
        $path = '/1.0/instances';
        if ($target) {
            $path .= '?target='.rawurlencode($target);
        }

        $response = $this->request($cluster)->timeout($timeout + 5)->post($path, $payload);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    /**
     * Import a locally built split image (metadata tarball + container rootfs) into the
     * cluster over the REST API and return its fingerprint. This is the REST-native
     * equivalent of `incus image import <metadata> <rootfs>`: a multipart POST to
     * /1.0/images with the two parts named exactly "metadata" and "rootfs" (container
     * rootfs). The call is async; the completed operation carries the fingerprint.
     *
     * The builder produces these two files on the build host; Laravel reads them and
     * uploads them through the same cert-scoped transport as every other call — the host
     * is never touched directly.
     */
    public function importImage(Cluster $cluster, string $metadataPath, string $rootfsPath, int $timeout = 600, ?string $alias = null, bool $replace = false): string
    {
        // Idempotent: if an alias is given and already resolves to an image,
        // reuse its fingerprint. The immutable model imports the same content
        // under the same per-revision alias (<repo>-<sha>), so re-running a
        // deploy of the same commit must not fail on "image already exists".
        //
        // EXCEPTION — $replace: some images reuse ONE fixed alias across rebuilds
        // (the CoreDNS resolver, "kixctl-coredns"), where the alias is NOT a
        // content hash. For those the short-circuit is wrong — it would relaunch
        // a stale image forever — so $replace deletes the old alias+image first
        // and imports the freshly built content.
        if ($alias !== null && ! $replace) {
            $existing = $this->imageFingerprintByAlias($cluster, $alias);
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($alias !== null && $replace) {
            $stale = $this->imageFingerprintByAlias($cluster, $alias);
            if ($stale !== null) {
                $this->deleteImage($cluster, $stale);
            }
        }

        foreach (['metadata' => $metadataPath, 'rootfs' => $rootfsPath] as $label => $path) {
            if (! is_readable($path)) {
                throw new \RuntimeException("Image {$label} not readable: {$path}");
            }
        }

        $response = $this->request($cluster)
            ->timeout($timeout + 5)
            ->attach('metadata', file_get_contents($metadataPath), 'metadata.tar.xz')
            ->attach('rootfs', file_get_contents($rootfsPath), 'rootfs.tar.xz')
            ->post('/1.0/images');
        $response->throw();

        // Async operation — wait and pull the fingerprint from its result metadata.
        $operation = $response->json('operation');
        $wait = $this->request($cluster)
            ->timeout($timeout + 5)
            ->get(rtrim((string) $operation, '/').'/wait', ['timeout' => $timeout]);
        $wait->throw();

        $result = $wait->json('metadata', []);
        if (($result['status'] ?? '') === 'Failure') {
            throw new \RuntimeException($result['err'] ?? 'Image import failed');
        }

        $fingerprint = $result['metadata']['fingerprint'] ?? null;
        if (! $fingerprint) {
            throw new \RuntimeException('Image import completed but returned no fingerprint');
        }

        // Tag the freshly imported image with the per-revision alias so future
        // deploys of the same commit are a no-op and the image carries a stable,
        // human-readable handle that mirrors the instance name.
        if ($alias !== null) {
            $this->createImageAlias($cluster, $alias, $fingerprint);
        }

        return $fingerprint;
    }

    /**
     * Resolve an image alias to its target fingerprint, or null if the alias
     * does not exist. Lets importImage short-circuit a duplicate per revision.
     */
    public function imageFingerprintByAlias(Cluster $cluster, string $alias): ?string
    {
        $response = $this->request($cluster)->get('/1.0/images/aliases/'.rawurlencode($alias));
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();

        return $response->json('metadata.target');
    }

    /**
     * Delete an image by fingerprint (async op; tolerates an already-gone
     * image). Deleting the image also removes any aliases pointing at it, so a
     * subsequent import can re-create the alias cleanly.
     */
    public function deleteImage(Cluster $cluster, string $fingerprint, int $timeout = 120): void
    {
        $response = $this->request($cluster)->delete('/1.0/images/'.rawurlencode($fingerprint));
        if ($response->status() === 404) {
            return;
        }
        $response->throw();

        $operation = $response->json('operation');
        if ($operation) {
            $this->request($cluster)
                ->timeout($timeout + 5)
                ->get(rtrim((string) $operation, '/').'/wait', ['timeout' => $timeout])
                ->throw();
        }
    }

    /** Attach an alias to an image fingerprint (tolerates an existing alias). */
    protected function createImageAlias(Cluster $cluster, string $alias, string $fingerprint): void
    {
        $response = $this->request($cluster)->post('/1.0/images/aliases', [
            'name' => $alias,
            'target' => $fingerprint,
        ]);
        if ($response->status() === 409) {
            return; // already present — fine for an idempotent re-deploy
        }
        $response->throw();
    }

    /** True if an instance of this name already exists on the cluster. */
    public function instanceExists(Cluster $cluster, string $name): bool
    {
        $response = $this->request($cluster)->get('/1.0/instances/'.rawurlencode($name));
        if ($response->status() === 404) {
            return false;
        }
        $response->throw();

        return true;
    }

    /** Best-effort primary global IPv4 of an instance from its live state, or null. */
    public function instanceIpv4(Cluster $cluster, string $name): ?string
    {
        $state = $this->get($cluster, '/1.0/instances/'.rawurlencode($name).'/state');

        return $this->primaryIpv4($state);
    }

    /**
     * REST-native equivalent of `incus launch <image> <name> -p <profile>
     * -c security.nesting=true --target <host>`: create an instance from an imported
     * image fingerprint (nesting on by default — mandatory for NixOS containers), placed
     * on an explicit cluster member (required on a cluster), then start it.
     */
    public function launchBuiltImage(
        Cluster $cluster,
        string $name,
        string $fingerprint,
        string $target,
        array $profiles = ['power'],
        array $config = [],
        array $credentials = [],
        int $timeout = 300,
        ?string $network = null
    ): void {
        // 1) Create the immutable revision — but do NOT start it yet. Per-app
        //    config must be on disk in the credstore BEFORE PID1 boots and
        //    enumerates it, so the sequence is create -> push -> start.
        $payload = [
            'name' => $name,
            'source' => ['type' => 'image', 'fingerprint' => $fingerprint],
            'profiles' => $profiles,
            'config' => array_merge(['security.nesting' => 'true'], $config),
        ];

        // Place the instance on a specific managed network by overriding eth0 as
        // an explicit NIC device. An instance-level device wins over any eth0 the
        // profile defines, so the NETWORK comes from here — never borrowed from
        // the operator's profile/LAN. Incus auto-configures address/DNS/NAT from
        // the managed bridge (devices_nic + About networking, Incus docs).
        if ($network !== null && $network !== '') {
            $payload['devices'] = [
                'eth0' => ['type' => 'nic', 'network' => $network],
            ];
        }

        $this->createInstance($cluster, $payload, $target, $timeout);

        // 2) Deliver per-app config as credstore files (0400 root-only) under
        //    /etc/credstore/<KEY>. systemd's ImportCredential=* enumerates that
        //    directory at boot as a system credential, so the app side is
        //    unchanged from the old systemd.credential.* delivery — the value is
        //    simply no longer visible in `incus config show`. /etc is used, not
        //    /run/credstore, because /run is a fresh tmpfs at boot and a file
        //    pushed there before start would be wiped. Verified end to end.
        if ($credentials !== []) {
            $dir = '/etc/credstore';
            $this->ensureInstanceDirectory($cluster, $name, $dir, 0700);
            foreach ($credentials as $key => $value) {
                $this->assertCredentialKey($key);
                $this->pushInstanceFile($cluster, $name, "{$dir}/{$key}", (string) $value, 0, 0, 0400);
            }
        }

        // 3) Start — the credentials are now present for ImportCredential=*.
        $this->setInstanceState($cluster, $name, 'start', 60);
    }

    /**
     * Guard a credential name before it becomes a credstore filename. systemd
     * credential names are filenames, so a slash, "..", control chars, or
     * anything outside the safe set could write outside /etc/credstore or be
     * silently ignored by systemd. Fail loudly instead.
     */
    protected function assertCredentialKey(string $key): void
    {
        if ($key === '' || str_contains($key, '..') || ! preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new \InvalidArgumentException("Unsafe credential key: {$key}");
        }
    }

    // ── Instance file operations (REST files API) ────────────────────────
    // REST-native equivalent of `incus file push` / `incus file create`. For
    // CONTAINERS these operations are handled directly by Incus and work
    // whether the instance is running or stopped (Incus mounts the rootfs) —
    // which is exactly what lets credstore delivery slot between create and
    // start. Request shape verified against the Incus client
    // (client/incus_instances.go, ProtocolIncus.CreateInstanceFile):
    //   POST /1.0/instances/{name}/files?path=<path>
    //   X-Incus-uid / X-Incus-gid (decimal), X-Incus-mode (octal, %04o),
    //   X-Incus-type (file|directory|symlink), X-Incus-write (overwrite|append).
    // The push is a SYNC response (no async operation), so nothing to wait on;
    // a non-2xx simply throws.
    //
    // Incus <7.1 regression (#3329): `file push` with uid/gid but no explicit
    // mode drops the mode to 0. We ALWAYS send an explicit mode and the fix
    // shipped in 7.1, so it can't bite us — but never omit the mode.
    public function pushInstanceFile(
        Cluster $cluster,
        string $instance,
        string $path,
        string $content,
        int $uid = 0,
        int $gid = 0,
        int $mode = 0600,
        string $type = 'file',
        string $writeMode = 'overwrite',
        int $timeout = 30,
    ): void {
        $enc = rawurlencode($instance);
        $url = "/1.0/instances/{$enc}/files?path=".rawurlencode($path);

        $headers = [
            'X-Incus-uid' => (string) $uid,
            'X-Incus-gid' => (string) $gid,
            'X-Incus-mode' => sprintf('%04o', $mode),
        ];
        if ($type !== '') {
            $headers['X-Incus-type'] = $type;
        }
        if ($writeMode !== '') {
            $headers['X-Incus-write'] = $writeMode;
        }

        $response = $this->request($cluster)
            ->timeout($timeout)
            ->withHeaders($headers)
            ->withBody($content, 'application/octet-stream')
            ->post($url);

        $response->throw();
    }

    /** True if a path already exists inside the instance (HEAD on the files API). */
    public function instancePathExists(Cluster $cluster, string $instance, string $path): bool
    {
        $enc = rawurlencode($instance);
        $url = "/1.0/instances/{$enc}/files?path=".rawurlencode($path);

        $response = $this->request($cluster)->head($url);
        if ($response->status() === 404) {
            return false;
        }
        $response->throw();

        return true;
    }

    /** Create a directory inside the instance if it isn't already present (idempotent). */
    public function ensureInstanceDirectory(Cluster $cluster, string $instance, string $path, int $mode = 0700): void
    {
        if ($this->instancePathExists($cluster, $instance, $path)) {
            return;
        }

        $this->pushInstanceFile($cluster, $instance, $path, '', 0, 0, $mode, 'directory', '');
    }

    public function instance(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);

        return $this->get($cluster, "/1.0/instances/{$encoded}", ['recursion' => 1]);
    }

    public function instanceConfig(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        $data = $this->get($cluster, "/1.0/instances/{$encoded}", ['recursion' => 1]);

        $devices = $data['expanded_devices'] ?? $data['devices'] ?? [];

        $disks = [];
        $nics = [];
        foreach ($devices as $devName => $dev) {
            $type = $dev['type'] ?? '';
            if ($type === 'disk') {
                $disks[] = [
                    'name' => $devName,
                    'path' => $dev['path'] ?? '',
                    'pool' => $dev['pool'] ?? '',
                    'source' => $dev['source'] ?? '',
                    'size' => $dev['size'] ?? '',
                    'is_root' => ($dev['path'] ?? '') === '/',
                ];
            } elseif ($type === 'nic') {
                $nics[] = [
                    'name' => $devName,
                    'nictype' => $dev['nictype'] ?? ($dev['network'] ?? ''),
                    'parent' => $dev['parent'] ?? '',
                    'vlan' => $dev['vlan'] ?? '',
                ];
            }
        }

        return [
            'profiles' => $data['profiles'] ?? [],
            'disks' => $disks,
            'nics' => $nics,
        ];
    }

    public function instanceLogs(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);

        // Incus returns a plain list of file URL strings (e.g.
        // "/1.0/instances/foo/logs/lxc.log"), sometimes nested under
        // "exec-output/". Normalize every entry to ['name' => <path under /logs/>]
        // so the view and auto-open can rely on a stable shape.
        return collect($this->get($cluster, "/1.0/instances/{$encoded}/logs", ['recursion' => 1]))
            ->map(function ($entry): ?array {
                if (is_array($entry)) {
                    $file = (string) ($entry['name'] ?? '');
                } else {
                    $entry = (string) $entry;
                    $pos = strpos($entry, '/logs/');
                    $file = $pos !== false ? substr($entry, $pos + 6) : basename($entry);
                }

                $file = trim($file, '/');

                return $file !== '' ? ['name' => $file] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function instanceLogFile(Cluster $cluster, string $name, string $file): string
    {
        $encoded = rawurlencode($name);
        // A log name may carry a subpath (e.g. "exec-output/exec_x.stdout"); encode
        // each segment so the slashes survive as real path separators.
        $encodedFile = implode('/', array_map('rawurlencode', explode('/', $file)));

        $response = $this->request($cluster)->get("/1.0/instances/{$encoded}/logs/{$encodedFile}");
        $response->throw();

        return $response->body();
    }

    public function consoleLog(Cluster $cluster, string $name): string
    {
        $encoded = rawurlencode($name);
        $response = $this->request($cluster)->get("/1.0/instances/{$encoded}/console");
        $response->throw();

        return $response->body();
    }

    public function snapshots(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);

        return collect($this->get($cluster, "/1.0/instances/{$encoded}/snapshots", ['recursion' => 1]))
            ->map(fn ($s) => [
                'name' => $s['name'],
                'created_at' => $s['created_at'] ?? null,
                'stateful' => $s['stateful'] ?? false,
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function setInstanceState(Cluster $cluster, string $name, string $action, int $timeout = 30): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException("Unsupported action: {$action}");
        }

        $encoded = rawurlencode($name);
        $response = $this->request($cluster)->put("/1.0/instances/{$encoded}/state", [
            'action' => $action,
            'timeout' => $timeout,
            'force' => false,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    public function createSnapshot(Cluster $cluster, string $instance, string $snapshot, int $timeout = 60): void
    {
        $encoded = rawurlencode($instance);
        $response = $this->request($cluster)->post("/1.0/instances/{$encoded}/snapshots", [
            'name' => $snapshot,
            'stateful' => false,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    public function restoreSnapshot(Cluster $cluster, string $instance, string $snapshot, int $timeout = 60): void
    {
        $encoded = rawurlencode($instance);
        $response = $this->request($cluster)->put("/1.0/instances/{$encoded}", [
            'restore' => $snapshot,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    public function deleteSnapshot(Cluster $cluster, string $instance, string $snapshot, int $timeout = 30): void
    {
        $i = rawurlencode($instance);
        $s = rawurlencode($snapshot);
        $response = $this->request($cluster)->delete("/1.0/instances/{$i}/snapshots/{$s}");
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    // ── Streaming (async) snapshot variants ──────────────────────────────
    // These START the operation and hand back its URL WITHOUT blocking; the
    // StreamInstanceOperation job polls operation() every 0.5s and broadcasts
    // progress over Reverb. The synchronous createSnapshot/restoreSnapshot/
    // deleteSnapshot above are the blocking equivalents. Incus answers an async
    // verb with {"operation":"/1.0/operations/<uuid>", ...}; we return that URL.
    public function startCreateSnapshot(Cluster $cluster, string $instance, string $snapshot): string
    {
        $encoded = rawurlencode($instance);
        $response = $this->request($cluster)->post("/1.0/instances/{$encoded}/snapshots", [
            'name' => $snapshot,
            'stateful' => false,
        ]);
        $response->throw();

        return $this->operationUrl($response);
    }

    public function startRestoreSnapshot(Cluster $cluster, string $instance, string $snapshot): string
    {
        $encoded = rawurlencode($instance);
        $response = $this->request($cluster)->put("/1.0/instances/{$encoded}", [
            'restore' => $snapshot,
        ]);
        $response->throw();

        return $this->operationUrl($response);
    }

    public function startDeleteSnapshot(Cluster $cluster, string $instance, string $snapshot): string
    {
        $i = rawurlencode($instance);
        $s = rawurlencode($snapshot);
        $response = $this->request($cluster)->delete("/1.0/instances/{$i}/snapshots/{$s}");
        $response->throw();

        return $this->operationUrl($response);
    }

    /** Current state of an async operation by its URL (the Incus operation object). */
    public function operation(Cluster $cluster, string $operationUrl): array
    {
        return $this->get($cluster, $operationUrl);
    }

    /** Pull the async operation URL out of a start response, or fail loudly. */
    protected function operationUrl(Response $response): string
    {
        $url = $response->json('operation');
        if (! is_string($url) || $url === '') {
            throw new \RuntimeException('Incus did not return an async operation URL.');
        }

        return $url;
    }

    public function deleteInstance(Cluster $cluster, string $name, int $timeout = 60): void
    {
        $encoded = rawurlencode($name);

        try {
            $this->setInstanceState($cluster, $name, 'stop', 30);
        } catch (\Throwable $e) {
        }

        $response = $this->request($cluster)->delete("/1.0/instances/{$encoded}");
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    public function renameInstance(Cluster $cluster, string $oldName, string $newName, int $timeout = 60): void
    {
        $encoded = rawurlencode($oldName);
        $response = $this->request($cluster)->post("/1.0/instances/{$encoded}", [
            'name' => $newName,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    public function createStorageVolume(Cluster $cluster, string $pool, string $name, ?string $description = null): void
    {
        $encodedPool = rawurlencode($pool);
        $payload = [
            'name' => $name,
            'type' => 'custom',
            'content_type' => 'filesystem',
        ];

        if ($description) {
            $payload['description'] = $description;
        }

        $response = $this->request($cluster)->post("/1.0/storage-pools/{$encodedPool}/volumes/custom", $payload);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), 60);
    }

    public function deleteStorageVolume(Cluster $cluster, string $pool, string $name): void
    {
        $encodedPool = rawurlencode($pool);
        $encodedName = rawurlencode($name);

        $response = $this->request($cluster)->delete("/1.0/storage-pools/{$encodedPool}/volumes/custom/{$encodedName}");
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), 60);
    }

    /**
     * Create a managed network.
     *
     * Network create/update/delete are synchronous in Incus: the REST call
     * returns a standard response rather than a background operation, so unlike
     * instance and volume writes there is no operation URL to wait on.
     *
     * On a clustered server a network is created in two phases — first a pending
     * definition on every member (member-specific keys only), then a final
     * create with no target that instantiates it cluster-wide. On a standalone
     * server a single call is enough. A plain managed bridge carries no
     * member-specific configuration, so the pending phase sends only the name
     * and type, and the full configuration is applied on the final call.
     *
     * The description is applied as a follow-up update rather than on the create
     * itself: on the clustered two-phase path some Incus versions (verified on
     * 6.0.4) accept a top-level description on create but do not persist it. The
     * follow-up update behaves identically on every version, so the description
     * lands reliably whether the server honors it on create or not.
     */
    public function createNetwork(Cluster $cluster, string $name, string $type = 'bridge', array $config = [], ?string $description = null): void
    {
        $body = ['name' => $name, 'type' => $type];
        if ($config !== []) {
            $body['config'] = $config;
        }

        if ($this->topology($cluster)['enabled']) {
            foreach ($this->members($cluster) as $member) {
                $target = rawurlencode($member['name']);
                $pending = $this->request($cluster)
                    ->post("/1.0/networks?target={$target}", ['name' => $name, 'type' => $type]);
                $pending->throw();
            }
        }

        $response = $this->request($cluster)->post('/1.0/networks', $body);
        $response->throw();

        if ($description !== null && $description !== '') {
            $this->updateNetwork($cluster, $name, [], $description);
        }
    }

    /**
     * Update a managed network. PATCH merges: only the keys passed here change,
     * and every other setting on the network is preserved. Global configuration
     * keys apply cluster-wide, so no target is needed. An empty config array is
     * omitted from the request so a description-only update touches nothing else.
     */
    public function updateNetwork(Cluster $cluster, string $name, array $config = [], ?string $description = null): void
    {
        $encoded = rawurlencode($name);
        $body = [];
        if ($config !== []) {
            $body['config'] = $config;
        }
        if ($description !== null) {
            $body['description'] = $description;
        }

        $response = $this->request($cluster)->patch("/1.0/networks/{$encoded}", $body);
        $response->throw();
    }

    public function deleteNetwork(Cluster $cluster, string $name): void
    {
        $encoded = rawurlencode($name);
        $response = $this->request($cluster)->delete("/1.0/networks/{$encoded}");
        $response->throw();
    }

    /**
     * Create a profile. Synchronous, like the network writes. Profiles are not
     * member-specific, so a single global POST creates it cluster-wide — no
     * pending/target two-phase dance (unlike networks). kixctl uses this to own
     * its own baseline profile (`kix`: a root disk on an auto-resolved pool),
     * so a fresh box never has to borrow the operator's `default`/`power`.
     */
    public function createProfile(Cluster $cluster, string $name, array $devices = [], array $config = [], ?string $description = null): void
    {
        $body = ['name' => $name];
        if ($config !== []) {
            $body['config'] = $config;
        }
        if ($devices !== []) {
            $body['devices'] = $devices;
        }
        if ($description !== null && $description !== '') {
            $body['description'] = $description;
        }

        $response = $this->request($cluster)->post('/1.0/profiles', $body);
        $response->throw();
    }

    /**
     * Update a profile's definition. Synchronous, like the network writes.
     *
     * PATCH merges: only the config keys passed here change, every other config
     * key is preserved, and — critically — the request never carries a devices
     * map, so Incus keeps every existing device (root disk, NIC, ...) untouched.
     * This is verified against the profile PATCH handler, which sets the request
     * devices to the profile's existing devices whenever the body omits them.
     * That guarantee is what makes editing a widely-inherited profile safe: a
     * config or description change cannot strip storage or networking from the
     * instances that inherit it. Editing devices is deliberately out of scope
     * here and must never be added by sending a devices map from this method.
     */
    public function updateProfile(Cluster $cluster, string $name, array $config = [], ?string $description = null): void
    {
        $encoded = rawurlencode($name);
        $body = [];
        if ($config !== []) {
            $body['config'] = $config;
        }
        if ($description !== null) {
            $body['description'] = $description;
        }

        $response = $this->request($cluster)->patch("/1.0/profiles/{$encoded}", $body);
        $response->throw();
    }

    public function updateInstance(Cluster $cluster, string $name, array $payload, int $timeout = 60): void
    {
        $encoded = rawurlencode($name);
        $response = $this->request($cluster)->patch("/1.0/instances/{$encoded}", $payload);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    protected function waitForOperation(Cluster $cluster, ?string $operation, int $timeout): void
    {
        if (! $operation) {
            return;
        }

        $wait = $this->request($cluster)
            ->timeout($timeout + 5)
            ->get(rtrim($operation, '/').'/wait', ['timeout' => $timeout]);
        $wait->throw();

        $result = $wait->json('metadata', []);
        if (($result['status'] ?? '') === 'Failure') {
            throw new \RuntimeException($result['err'] ?? 'Operation failed');
        }
    }

    protected function primaryIpv4(?array $state): ?string
    {
        if (! $state) {
            return null;
        }

        $skip = ['lo', 'docker', 'hassio', 'veth', 'br-', 'virbr',
            'cni', 'flannel', 'wg', 'tailscale', 'zt', 'kube', 'cali'];

        $candidates = [];
        foreach ($state['network'] ?? [] as $iface => $data) {
            foreach ($skip as $prefix) {
                if (str_starts_with($iface, $prefix)) {
                    continue 2;
                }
            }
            foreach ($data['addresses'] ?? [] as $addr) {
                if (($addr['family'] ?? '') === 'inet' && ($addr['scope'] ?? '') === 'global') {
                    $candidates[] = $addr['address'];
                }
            }
        }

        foreach ($candidates as $ip) {
            if (! str_starts_with($ip, '172.')) {
                return $ip;
            }
        }

        return $candidates[0] ?? null;
    }

    protected function get(Cluster $cluster, string $path, array $query = []): array
    {
        $response = $this->request($cluster)->get($path, $query);
        $response->throw();

        return $response->json('metadata', []);
    }

    protected function getRaw(Cluster $cluster, string $path, array $query = []): string
    {
        $response = $this->request($cluster)->get($path, $query);
        $response->throw();

        return $response->body();
    }

    protected function request(Cluster $cluster): PendingRequest
    {
        $c = $cluster->connection;

        if (($c['driver'] ?? 'socket') === 'socket') {
            return Http::baseUrl('http://incus')
                ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $c['socket']]])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10);
        }

        return Http::baseUrl($c['url'])
            ->withOptions([
                'cert' => $this->materializeCredential($c['client_cert']),
                'ssl_key' => $this->materializeCredential($c['client_key']),
                'verify' => $c['verify'] ?? false,
            ])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }
}
