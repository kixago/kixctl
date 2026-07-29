<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Models\Profile;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Profiles\ProfileManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for the classified, live-safe profile EDIT path — the
 * sibling of kixctl:network-edit-probe. It proves the read-modify-write PUT does
 * exactly one thing at a time and never strips the rest of the profile:
 *
 *   1. create a throwaway managed profile (root disk on an auto-resolved pool),
 *   2. attach an eth0 NIC on the default network -> assert eth0 appears AND the
 *      root disk is still there (device edit didn't wipe storage),
 *   3. detach the NIC -> assert eth0 is gone AND the root disk still there,
 *   4. edit the description -> assert it synced AND devices survived,
 *   5. delete.
 *
 *   php artisan kixctl:profile-edit-probe
 *   php artisan kixctl:profile-edit-probe --key=kixedit9 --network=kixbr0
 */
class ProfileEditProbe extends Command
{
    protected $signature = 'kixctl:profile-edit-probe
        {--key=kixeditprobe0 : Throwaway profile key / Incus profile name}
        {--network= : Network key to attach as eth0 (default: the default network row)}
        {--keep : Do not delete after editing}';

    protected $description = 'Prove ProfileManager edits (NIC attach/detach + description) are live-safe read-modify-writes';

    public function handle(ProfileManager $manager, IncusClient $incus, ClusterRegistry $registry): int
    {
        $key = (string) $this->option('key');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        $network = (string) ($this->option('network') ?: (Network::default()?->key ?? 'kixbr0'));

        if (Profile::query()->where('key', $key)->exists() || $incus->profileExists($cluster, $key)) {
            $this->error("'{$key}' already exists (row or profile). Pick another --key or clean it up first.");

            return self::FAILURE;
        }

        $this->info("Creating managed profile '{$key}'…");
        $profile = $manager->create(['key' => $key, 'label' => 'edit probe']);

        $before = $incus->profile($cluster, $key);
        $rootPool = $before['devices']['root']['pool'] ?? '?';
        $this->info("Baseline: devices=".implode(',', array_keys($before['devices'] ?? []))."  root-pool={$rootPool}");

        // 2) Attach eth0 on the default (or given) network.
        $this->info("Attaching eth0 on '{$network}'…");
        $manager->update($profile, ['nic_network' => $network]);
        $afterAttach = $incus->profile($cluster, $key);
        if (! isset($afterAttach['devices']['eth0'])) {
            $this->error('eth0 NIC did not appear after attach.');

            return $this->cleanup($manager, $profile, $incus, $cluster, $key);
        }
        if (! isset($afterAttach['devices']['root'])) {
            $this->error('Root disk VANISHED after attaching the NIC — the PUT stripped storage. STOP.');

            return $this->cleanup($manager, $profile, $incus, $cluster, $key);
        }
        $nicNet = $afterAttach['devices']['eth0']['network'] ?? '?';
        $this->info("eth0 present on '{$nicNet}'; root disk survived. Good.");

        // 3) Detach eth0.
        $this->info('Detaching eth0…');
        $manager->update($profile, ['nic_network' => '']);
        $afterDetach = $incus->profile($cluster, $key);
        if (isset($afterDetach['devices']['eth0'])) {
            $this->error('eth0 NIC is STILL present after detach.');

            return $this->cleanup($manager, $profile, $incus, $cluster, $key);
        }
        if (! isset($afterDetach['devices']['root'])) {
            $this->error('Root disk VANISHED after detaching the NIC. STOP.');

            return $this->cleanup($manager, $profile, $incus, $cluster, $key);
        }
        $this->info('eth0 gone; root disk survived. Good.');

        // 4) Description edit re-syncs to Incus without touching devices.
        $this->info('Editing description…');
        $manager->update($profile, ['description' => 'edited by profile-edit-probe']);
        $afterDesc = $incus->profile($cluster, $key);
        if (($afterDesc['description'] ?? '') !== 'edited by profile-edit-probe') {
            $this->error('Description did not sync to Incus.');

            return $this->cleanup($manager, $profile, $incus, $cluster, $key);
        }
        if (! isset($afterDesc['devices']['root'])) {
            $this->error('Root disk VANISHED after a description edit. STOP.');

            return $this->cleanup($manager, $profile, $incus, $cluster, $key);
        }
        $this->info('Description synced; devices intact.');

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$key}' in place.");

            return self::SUCCESS;
        }

        $this->info("Deleting '{$key}'…");
        $manager->delete($profile);
        if ($incus->profileExists($cluster, $key)) {
            $this->error('Delete returned but the profile is STILL present.');

            return self::FAILURE;
        }

        $this->info('Clean: every edit was a live-safe read-modify-write; root disk never stripped. Proven.');

        return self::SUCCESS;
    }

    /** Best-effort teardown so a failed run doesn't leave a throwaway behind. */
    private function cleanup(ProfileManager $manager, Profile $profile, IncusClient $incus, $cluster, string $key): int
    {
        try {
            if ($incus->profileExists($cluster, $key)) {
                $manager->delete($profile);
            }
        } catch (\Throwable $e) {
            $this->warn("Cleanup of '{$key}' failed: {$e->getMessage()} — remove it by hand.");
        }

        return self::FAILURE;
    }
}
