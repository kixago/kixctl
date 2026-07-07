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

    /**
     * Start / stop / restart an instance. This is an async Incus operation:
     * the PUT returns an operation URL, and we block on /wait until it finishes.
     */
    public function setInstanceState(Cluster $cluster, string $name, string $action, int $timeout = 30): void
    {
        // Whitelist the verb — this is client-influenced input reaching the cluster API.
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException("Unsupported action: {$action}");
        }

        $encoded = rawurlencode($name); // never let a raw name shape the URL path

        $response = $this->request($cluster)->put("/1.0/instances/{$encoded}/state", [
            'action'  => $action,
            'timeout' => $timeout,
            'force'   => false, // graceful; a "force stop" modifier can come later
        ]);
        $response->throw();

        $operation = $response->json('operation'); // e.g. "/1.0/operations/<uuid>"
        if (! $operation) {
            return; // synchronous response, nothing to wait on
        }

        // Block until the operation completes (or the timeout elapses).
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
