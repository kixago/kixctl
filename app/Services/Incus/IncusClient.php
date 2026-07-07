<?php

namespace App\Services\Incus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class IncusClient
{
    public function members(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/cluster/members', ['recursion' => 1]))
            ->map(fn($m) => [
                'cluster'       => $cluster->key,
                'cluster_label' => $cluster->label,
                'name'    => $m['server_name'],
                'status'  => $m['status'],
                'message' => $m['message'] ?? '',
                'url'     => $m['url'] ?? '',
                'roles'   => $m['roles'] ?? [],
            ])
            ->all();
    }
    /** Live resource state for one node: memory, load, storage pools. */
    public function memberState(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        $data = $this->get($cluster, "/1.0/cluster/members/{$encoded}/state");

        $sys = $data['sysinfo'] ?? [];

        $totalRam = $sys['total_ram'] ?? 0;
        $freeRam  = $sys['free_ram'] ?? 0;
        $buffered = $sys['buffered_ram'] ?? 0;
        // Real used = total - free - buffers/cache (cache isn't true pressure).
        $usedRam  = max(0, $totalRam - $freeRam - $buffered);

        $pool = collect($data['storage_pools'] ?? [])
            ->map(fn($p, $poolName) => [
                'name'  => $poolName,
                'total' => $p['space']['total'] ?? 0,
                'used'  => $p['space']['used'] ?? 0,
            ])
            ->sortByDesc('total')
            ->first();

        return [
            'ram_total' => $totalRam,
            'ram_used'  => $usedRam,
            'ram_pct'   => $totalRam > 0 ? round($usedRam / $totalRam * 100, 1) : 0,
            'load'      => $sys['load_averages'] ?? [0, 0, 0],
            'processes' => $sys['processes'] ?? 0,
            'pool_name'  => $pool['name'] ?? null,
            'pool_total' => $pool['total'] ?? 0,
            'pool_used'  => $pool['used'] ?? 0,
            'pool_pct'   => ($pool['total'] ?? 0) > 0 ? round($pool['used'] / $pool['total'] * 100, 1) : 0,
        ];
    }
    public function instances(Cluster $cluster): array
    {
        return collect($this->get($cluster, '/1.0/instances', ['recursion' => 2]))
            ->map(fn($i) => [
                'cluster'       => $cluster->key,
                'cluster_label' => $cluster->label,
                'name'   => $i['name'],
                'type'   => $i['type'],
                'status' => $i['status'],
                'node'   => $i['location'] ?? '—',
                'ipv4'   => $this->primaryIpv4($i['state'] ?? null),
            ])
            ->sortBy('node')
            ->values()
            ->all();
    }

    /** Full detail for one instance (config, state, limits). */
    public function instance(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        return $this->get($cluster, "/1.0/instances/{$encoded}", ['recursion' => 1]);
    }
    /** Devices + profiles for an instance, with profile inheritance merged in. */
    public function instanceConfig(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        $data = $this->get($cluster, "/1.0/instances/{$encoded}", ['recursion' => 1]);

        $devices = $data['expanded_devices'] ?? $data['devices'] ?? [];

        $disks = [];
        $nics  = [];
        foreach ($devices as $devName => $dev) {
            $type = $dev['type'] ?? '';
            if ($type === 'disk') {
                $disks[] = [
                    'name'    => $devName,
                    'path'    => $dev['path'] ?? '',
                    'pool'    => $dev['pool'] ?? '',
                    'source'  => $dev['source'] ?? '',
                    'size'    => $dev['size'] ?? '',
                    'is_root' => ($dev['path'] ?? '') === '/',
                ];
            } elseif ($type === 'nic') {
                $nics[] = [
                    'name'    => $devName,
                    'nictype' => $dev['nictype'] ?? ($dev['network'] ?? ''),
                    'parent'  => $dev['parent'] ?? '',
                    'vlan'    => $dev['vlan'] ?? '',
                ];
            }
        }

        return [
            'profiles' => $data['profiles'] ?? [],
            'disks'    => $disks,
            'nics'     => $nics,
        ];
    }
    /** List an instance's snapshots (names + creation times). */
    public function snapshots(Cluster $cluster, string $name): array
    {
        $encoded = rawurlencode($name);
        return collect($this->get($cluster, "/1.0/instances/{$encoded}/snapshots", ['recursion' => 1]))
            ->map(fn($s) => [
                'name'       => $s['name'],
                'created_at' => $s['created_at'] ?? null,
                'stateful'   => $s['stateful'] ?? false,
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /** Start / stop / restart an instance. Async operation. */
    public function setInstanceState(Cluster $cluster, string $name, string $action, int $timeout = 30): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException("Unsupported action: {$action}");
        }

        $encoded = rawurlencode($name);
        $response = $this->request($cluster)->put("/1.0/instances/{$encoded}/state", [
            'action'  => $action,
            'timeout' => $timeout,
            'force'   => false,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    /** Create a snapshot. Async. */
    public function createSnapshot(Cluster $cluster, string $instance, string $snapshot, int $timeout = 60): void
    {
        $encoded = rawurlencode($instance);
        $response = $this->request($cluster)->post("/1.0/instances/{$encoded}/snapshots", [
            'name'     => $snapshot,
            'stateful' => false,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    /** Restore an instance to a snapshot. Async, destructive. */
    public function restoreSnapshot(Cluster $cluster, string $instance, string $snapshot, int $timeout = 60): void
    {
        $encoded = rawurlencode($instance);
        $response = $this->request($cluster)->put("/1.0/instances/{$encoded}", [
            'restore' => $snapshot,
        ]);
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    /** Delete a snapshot. Async. */
    public function deleteSnapshot(Cluster $cluster, string $instance, string $snapshot, int $timeout = 30): void
    {
        $i = rawurlencode($instance);
        $s = rawurlencode($snapshot);
        $response = $this->request($cluster)->delete("/1.0/instances/{$i}/snapshots/{$s}");
        $response->throw();
        $this->waitForOperation($cluster, $response->json('operation'), $timeout);
    }

    /** Block until an async Incus operation finishes; throw on failure. */
    protected function waitForOperation(Cluster $cluster, ?string $operation, int $timeout): void
    {
        if (! $operation) {
            return;
        }

        $wait = $this->request($cluster)
            ->timeout($timeout + 5)
            ->get(rtrim($operation, '/') . '/wait', ['timeout' => $timeout]);
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

        // Interface name prefixes that are virtual / container-internal and
        // never the address you'd actually reach an instance on.
        $skip = ['lo', 'docker', 'hassio', 'veth', 'br-', 'virbr',
            'cni', 'flannel', 'wg', 'tailscale', 'zt', 'kube', 'cali'];

        $candidates = [];
        foreach ($state['network'] ?? [] as $iface => $data) {
            foreach ($skip as $prefix) {
                if (str_starts_with($iface, $prefix)) {
                    continue 2; // skip this interface entirely
                }
            }
            foreach ($data['addresses'] ?? [] as $addr) {
                if (($addr['family'] ?? '') === 'inet' && ($addr['scope'] ?? '') === 'global') {
                    $candidates[] = $addr['address'];
                }
            }
        }

        // Prefer a LAN address over Docker's default 172.x range as a backstop.
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

    protected function request(Cluster $cluster): PendingRequest
    {
        $c = $cluster->connection;

        if (($c['driver'] ?? 'socket') === 'socket') {
            return Http::baseUrl('http://incus')
                ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $c['socket']]])
                ->acceptJson()
                ->timeout(10);
        }

        return Http::baseUrl($c['url'])
            ->withOptions([
                'cert'    => $c['client_cert'],
                'ssl_key' => $c['client_key'],
                'verify'  => $c['verify'] ?? false,
            ])
            ->acceptJson()
            ->timeout(10);
    }
}
