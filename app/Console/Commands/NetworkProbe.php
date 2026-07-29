<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Networks\NetworkManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for Networks CRUD — proves NetworkManager creates a REAL
 * Incus bridge and deletes it cleanly on the live cluster, BEFORE any Filament
 * table drives those cluster mutations. Creates a throwaway managed network,
 * confirms the bridge exists (and shows its auto-subnet), then deletes it and
 * confirms both the bridge and the row are gone.
 *
 *   php artisan kixctl:network-probe                 # create + verify + delete
 *   php artisan kixctl:network-probe --keep          # leave it for hand inspection
 *   php artisan kixctl:network-probe --key=kixtest9  # custom throwaway key
 */
class NetworkProbe extends Command
{
    protected $signature = 'kixctl:network-probe
        {--key=kixprobe0 : Throwaway network key / bridge name}
        {--keep : Do not delete after creating}';

    protected $description = 'Prove NetworkManager creates + deletes a real Incus bridge (isolation test)';

    public function handle(NetworkManager $manager, IncusClient $incus, ClusterRegistry $registry): int
    {
        $key = (string) $this->option('key');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        if (Network::query()->where('key', $key)->exists() || $incus->networkExists($cluster, $key)) {
            $this->error("'{$key}' already exists (row or bridge). Pick another --key or clean it up first.");

            return self::FAILURE;
        }

        $this->info("Creating managed network '{$key}' (auto-subnet, NAT, DHCP)…");
        $network = $manager->create([
            'key' => $key,
            'label' => 'probe network',
            'ipv4_cidr' => null, // auto-subnet
            'ipv4_nat' => true,
            'ipv4_dhcp' => true,
            'isolation' => 'open',
        ]);

        if (! $incus->networkExists($cluster, $key)) {
            $this->error('Row created but the Incus bridge is missing — create did NOT sync.');

            return self::FAILURE;
        }

        $info = $incus->network($cluster, $key);
        $subnet = $info['config']['ipv4.address'] ?? '?';
        $this->info("Bridge live: status={$info['status']}  subnet={$subnet}  used_by={$info['used_by']}");

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$key}' in place. Remove it later via the table or tinker.");

            return self::SUCCESS;
        }

        $this->info("Deleting '{$key}'…");
        $manager->delete($network);

        if ($incus->networkExists($cluster, $key)) {
            $this->error('Delete returned but the bridge is STILL present.');

            return self::FAILURE;
        }
        if (Network::query()->where('key', $key)->exists()) {
            $this->error('Bridge gone but the row remains.');

            return self::FAILURE;
        }

        $this->info('Clean: bridge and row both gone. NetworkManager create + delete proven.');

        return self::SUCCESS;
    }
}
