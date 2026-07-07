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
        foreach ($state['network'] ?? [] as $iface => $data) {
            if ($iface === 'lo') {
                continue;
            }
            foreach ($data['addresses'] ?? [] as $addr) {
                if (($addr['family'] ?? '') === 'inet' && ($addr['scope'] ?? '') === 'global') {
                    return $addr['address'];
                }
            }
        }
        return null;
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
