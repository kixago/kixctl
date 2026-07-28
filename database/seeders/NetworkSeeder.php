<?php

namespace Database\Seeders;

use App\Models\Network;
use Illuminate\Database\Seeder;

/**
 * Seeds the one default network — kixbr0 — from config/networks.php. Idempotent:
 * re-running keeps the row's id and re-asserts the config defaults. This is the
 * whole of N1's data: a single managed, auto-subnet, NAT+DHCP, open, is_default
 * row. Additional networks (kixbr1, workbr0, …) become real via the N2 CRUD.
 */
class NetworkSeeder extends Seeder
{
    public function run(): void
    {
        $d = Network::defaults();

        Network::updateOrCreate(['key' => $d['key']], $d);
    }
}
