<?php

namespace App\Services\Incus;

/**
 * The list of clusters this dashboard manages.
 *
 * Today: one cluster, built from config (your local connection).
 * Later: this method reads a `clusters` table and maps each row to a Cluster —
 * the ONLY place that changes when customer clusters arrive.
 */
class ClusterRegistry
{
    /** @return Cluster[] */
    public function all(): array
    {
        return [
            new Cluster(
                key: 'local',
                label: config('incus.label', 'My Cluster'),
                connection: [
                    'driver'      => config('incus.driver'),
                    'socket'      => config('incus.socket'),
                    'url'         => config('incus.url'),
                    'client_cert' => config('incus.client_cert'),
                    'client_key'  => config('incus.client_key'),
                    'verify'      => config('incus.verify'),
                ],
            ),
        ];
    }

    public function find(string $key): ?Cluster
    {
        foreach ($this->all() as $cluster) {
            if ($cluster->key === $key) {
                return $cluster;
            }
        }
        return null;
    }
}
