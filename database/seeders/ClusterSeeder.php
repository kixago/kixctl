<?php

namespace Database\Seeders;

use App\Models\Cluster;
use Illuminate\Database\Seeder;

class ClusterSeeder extends Seeder
{
    public function run(): void
    {
        $certPath = config('incus.client_cert');
        $keyPath = config('incus.client_key');

        Cluster::updateOrCreate(
            ['key' => 'local'],
            [
                'label' => config('incus.label', 'My Cluster'),
                'driver' => config('incus.driver', 'https'),
                'url' => config('incus.url'),
                'socket' => config('incus.socket'),
                'client_cert' => ($certPath && is_file($certPath)) ? file_get_contents($certPath) : null,
                'client_key' => ($keyPath && is_file($keyPath)) ? file_get_contents($keyPath) : null,
                'verify' => (bool) config('incus.verify', false),
                'is_active' => true,
                'sort' => 0,
            ],
        );
    }
}
