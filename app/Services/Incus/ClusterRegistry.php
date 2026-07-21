<?php

namespace App\Services\Incus;

use App\Models\Cluster as ClusterModel;

/**
 * The list of clusters this dashboard manages.
 *
 * Reads the `clusters` table and maps each active row to a Cluster value object
 * via toEndpoint(). Single source of clusters for the whole app — page, widget,
 * create form, instance detail. (Config/.env INCUS_* are now seed-only.)
 */
class ClusterRegistry
{
    /** @return Cluster[] */
    public function all(): array
    {
        return ClusterModel::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (ClusterModel $c) => $c->toEndpoint())
            ->all();
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
