<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Networks\NetworkManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for register-unmanaged — the SAFETY probe. Registers an
 * existing operator-owned bridge (default: br0, which carries the whole fleet)
 * as an unmanaged reference, proves registering mutates NOTHING on the real
 * bridge, then unregisters and proves the bridge AND all its instances are still
 * there. This is the "kixctl never touches infra you didn't build" guarantee,
 * checked against your actual br0 before any UI can trigger it.
 *
 *   php artisan kixctl:network-register-probe            # uses br0
 *   php artisan kixctl:network-register-probe --key=br28
 */
class NetworkRegisterProbe extends Command
{
    protected $signature = 'kixctl:network-register-probe
        {--key=br0 : Existing, operator-owned network to register + unregister}';

    protected $description = 'Prove register/unregister of an unmanaged network never mutates the real bridge';

    public function handle(NetworkManager $manager, IncusClient $incus, ClusterRegistry $registry): int
    {
        $key = (string) $this->option('key');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }
        if (! $incus->networkExists($cluster, $key)) {
            $this->error("No bridge '{$key}' on the cluster to register.");

            return self::FAILURE;
        }
        if (Network::query()->where('key', $key)->exists()) {
            $this->error("'{$key}' already has a kixctl row. Remove it first.");

            return self::FAILURE;
        }

        $before = $incus->network($cluster, $key);
        $beforeUsed = (int) ($before['used_by'] ?? 0);
        $beforeDesc = (string) ($before['description'] ?? '');
        $beforeManaged = (bool) ($before['managed'] ?? false);
        $this->info("Before:  '{$key}' exists, managed=".($beforeManaged ? 'true' : 'false').", used_by={$beforeUsed}");

        // --- Register ---------------------------------------------------------
        $this->info("Registering '{$key}' as an unmanaged reference…");
        $network = $manager->register(['key' => $key, 'label' => 'probe reference']);

        if ($network->managed) {
            $this->error('Row was created managed=true — register must make an UNMANAGED row.');
            $network->delete();

            return self::FAILURE;
        }

        $mid = $incus->network($cluster, $key);
        if ((int) ($mid['used_by'] ?? 0) !== $beforeUsed
            || (string) ($mid['description'] ?? '') !== $beforeDesc
            || (bool) ($mid['managed'] ?? false) !== $beforeManaged) {
            $this->error("Registering MUTATED '{$key}' (used_by/description/managed changed). Cleaning up row and aborting.");
            $network->delete();

            return self::FAILURE;
        }
        $this->info("After register: '{$key}' untouched (used_by={$beforeUsed}); row is managed=false.");

        // --- Unregister -------------------------------------------------------
        $this->info('Unregistering (delete the row only)…');
        $manager->delete($network);

        if (Network::query()->where('key', $key)->exists()) {
            $this->error('Row still present after unregister.');

            return self::FAILURE;
        }
        if (! $incus->networkExists($cluster, $key)) {
            $this->error("CRITICAL: '{$key}' bridge was DELETED by unregister — this must NEVER happen.");

            return self::FAILURE;
        }

        $after = $incus->network($cluster, $key);
        if ((int) ($after['used_by'] ?? 0) !== $beforeUsed) {
            $this->error("'{$key}' used_by changed after unregister ({$beforeUsed} -> ".($after['used_by'] ?? '?').').');

            return self::FAILURE;
        }

        $this->info("After unregister: '{$key}' STILL present, used_by={$beforeUsed}, its instances untouched.");
        $this->info('register/unregister proven: the operator\'s bridge is never mutated.');

        return self::SUCCESS;
    }
}
