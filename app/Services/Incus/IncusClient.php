<?php

namespace App\Services\Incus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class IncusClient
{
    /** Cluster members, shaped like `incus cluster list`. */
    public function members(): array
    {
        return collect($this->get('/1.0/cluster/members', ['recursion' => 1]))
            ->map(fn($m) => [
                'name'    => $m['server_name'],
                'status'  => $m['status'],
                'message' => $m['message'] ?? '',
                'url'     => $m['url'] ?? '',
                'roles'   => $m['roles'] ?? [],
            ])
            ->all();
    }

    /** Every instance across every node, shaped like `incus list`. */
    public function instances(): array
    {
        return collect($this->get('/1.0/instances', ['recursion' => 2]))
            ->map(fn($i) => [
                'name'   => $i['name'],
                'type'   => $i['type'],                 // container | virtual-machine
                'status' => $i['status'],               // Running | Stopped | ...
                'node'   => $i['location'] ?? '—',
                'ipv4'   => $this->primaryIpv4($i['state'] ?? null),
            ])
            ->sortBy('node')
            ->values()
            ->all();
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

    /** GET an Incus API path and return its `metadata` payload. */
    protected function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query);
        $response->throw();
        return $response->json('metadata', []);
    }

    protected function request(): PendingRequest
    {
        $cfg = config('incus');

        if ($cfg['driver'] === 'socket') {
            // Host in the URL is a dummy; curl talks to the unix socket.
            return Http::baseUrl('http://incus')
                ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $cfg['socket']]])
                ->acceptJson()
                ->timeout(10);
        }

        // Remote cluster, least-privilege client cert.
        return Http::baseUrl($cfg['url'])
            ->withOptions([
                'cert'    => $cfg['client_cert'],
                'ssl_key' => $cfg['client_key'],
                'verify'  => $cfg['verify'],
            ])
            ->acceptJson()
            ->timeout(10);
    }
}
