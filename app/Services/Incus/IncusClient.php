<?php

namespace App\Services\Incus;

use Illuminate\Http\Client\PendingRequest;
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
                'used_by' => count($n['used_by'] ?? []),
            ])
            ->sortByDesc('managed')
            ->values()
            ->all();
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

        return $this->get($cluster, "/1.0/instances/{$encoded}/logs", ['recursion' => 1]);
    }

    public function instanceLogFile(Cluster $cluster, string $name, string $file): string
    {
        $encoded = rawurlencode($name);
        $encodedFile = rawurlencode($file);

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
