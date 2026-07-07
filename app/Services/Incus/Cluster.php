<?php

namespace App\Services\Incus;

/**
 * A named Incus endpoint. Today there's one (yours). Later, customer clusters
 * become rows in Postgres and get turned into these — the rest of the app
 * never needs to know the difference.
 */
final class Cluster
{
    public function __construct(
        public string $key,        // stable slug, e.g. 'local', 'acme'
        public string $label,      // human name shown in the UI
        public array $connection,  // how to reach this cluster's Incus API
    ) {}
}
