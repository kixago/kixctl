<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Networks\NetworkManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for Networks EDIT — proves NetworkManager::update() flips a
 * live-safe field (NAT) on the real bridge WITHOUT wiping the subnet, and that a
 * metadata edit round-trips, before the Edit form is wired. Toggles NAT off then
 * back on, checking the live config each time, and confirms ipv4.address survives
 * the PATCH (the whole reason update() is safe).
 *
 *   php artisan kixctl:network-edit-probe --key=kixbr1
 */
class NetworkEditProbe extends Command
{
    protected $signature = 'kixctl:network-edit-probe
        {--key=kixbr1 : Existing managed network to poke (NOT the locked default)}';

    protected $description = 'Prove NetworkManager::update() flips NAT live and preserves the subnet (isolation test)';

    public function handle(NetworkManager $manager, IncusClient $incus, ClusterRegistry $registry): int
    {
        $key = (string) $this->option('key');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        $network = Network::query()->where('key', $key)->first();
        if (! $network) {
            $this->error("No network row '{$key}'. Create one first (e.g. via the table).");

            return self::FAILURE;
        }
        if ($network->is_locked) {
            $this->error("'{$key}' is the locked default — pick a non-locked network to probe.");

            return self::FAILURE;
        }
        if (! $incus->networkExists($cluster, $key)) {
            $this->error("Row '{$key}' has no live bridge to edit.");

            return self::FAILURE;
        }

        $before = $incus->network($cluster, $key)['config'] ?? [];
        $subnet = $before['ipv4.address'] ?? '?';
        $nat = ($before['ipv4.nat'] ?? '') === 'true';
        $this->info("Before:  nat=".($nat ? 'true' : 'false')."  subnet={$subnet}");

        // Flip NAT to the opposite of its current value.
        $this->info('Flipping NAT via update()…');
        $manager->update($network, ['ipv4_nat' => ! $nat]);

        $after = $incus->network($cluster, $key)['config'] ?? [];
        $natAfter = ($after['ipv4.nat'] ?? '') === 'true';
        $subnetAfter = $after['ipv4.address'] ?? '?';
        $this->info("After:   nat=".($natAfter ? 'true' : 'false')."  subnet={$subnetAfter}");

        if ($natAfter === $nat) {
            $this->error('NAT did NOT change on the live bridge.');

            return self::FAILURE;
        }
        if ($subnetAfter !== $subnet) {
            $this->error("Subnet CHANGED during a NAT-only edit ({$subnet} -> {$subnetAfter}) — PATCH is not merging!");

            return self::FAILURE;
        }

        // Metadata round-trip: bump the label, then restore it.
        $origLabel = $network->label;
        $manager->update($network->refresh(), ['label' => $origLabel.' (edited)']);
        $reloaded = $network->refresh();
        $labelOk = $reloaded->label === $origLabel.' (edited)';
        $manager->update($reloaded, ['label' => $origLabel]);

        // Restore NAT to where we found it, leaving the network as it was.
        $manager->update($network->refresh(), ['ipv4_nat' => $nat]);

        $this->info('Metadata edit round-trip: '.($labelOk ? 'ok' : 'FAILED'));
        $this->info("Restored '{$key}' to nat=".($nat ? 'true' : 'false')." label='{$origLabel}'.");

        if (! $labelOk) {
            return self::FAILURE;
        }

        $this->info('update() proven: NAT flipped live, subnet preserved, metadata round-trips.');

        return self::SUCCESS;
    }
}
