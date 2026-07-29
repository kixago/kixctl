<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Profiles\ProfileManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for Profiles CRUD — proves ProfileManager creates a REAL
 * Incus profile (a root disk on an auto-resolved pool) and deletes it cleanly on
 * the live cluster, BEFORE any Filament UI drives those cluster mutations. The
 * exact sibling of kixctl:network-probe. Creates a throwaway managed profile,
 * confirms it exists (and shows the pool its root disk landed on), then deletes
 * it and confirms both the profile and the row are gone.
 *
 *   php artisan kixctl:profile-probe                  # create + verify + delete
 *   php artisan kixctl:profile-probe --keep           # leave it for hand inspection
 *   php artisan kixctl:profile-probe --key=kixtest9   # custom throwaway key
 */
class ProfileProbe extends Command
{
    protected $signature = 'kixctl:profile-probe
        {--key=kixprobe0 : Throwaway profile key / Incus profile name}
        {--keep : Do not delete after creating}';

    protected $description = 'Prove ProfileManager creates + deletes a real Incus profile (isolation test)';

    public function handle(ProfileManager $manager, IncusClient $incus, ClusterRegistry $registry): int
    {
        $key = (string) $this->option('key');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        if (Profile::query()->where('key', $key)->exists() || $incus->profileExists($cluster, $key)) {
            $this->error("'{$key}' already exists (row or profile). Pick another --key or clean it up first.");

            return self::FAILURE;
        }

        $this->info("Creating managed profile '{$key}' (root disk on an auto-resolved pool)…");
        $profile = $manager->create([
            'key' => $key,
            'label' => 'probe profile',
        ]);

        if (! $incus->profileExists($cluster, $key)) {
            $this->error('Row created but the Incus profile is missing — create did NOT sync.');

            return self::FAILURE;
        }

        $live = $incus->profile($cluster, $key);
        $pool = $live['devices']['root']['pool'] ?? '?';
        $usedBy = is_countable($live['used_by'] ?? null) ? count($live['used_by']) : 0;
        $this->info("Profile live: root-disk pool={$pool}  devices=".implode(',', array_keys($live['devices'] ?? []))."  used_by={$usedBy}");

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$key}' in place. Remove it later via the table or tinker.");

            return self::SUCCESS;
        }

        $this->info("Deleting '{$key}'…");
        $manager->delete($profile);

        if ($incus->profileExists($cluster, $key)) {
            $this->error('Delete returned but the profile is STILL present.');

            return self::FAILURE;
        }
        if (Profile::query()->where('key', $key)->exists()) {
            $this->error('Profile gone but the row remains.');

            return self::FAILURE;
        }

        $this->info('Clean: profile and row both gone. ProfileManager create + delete proven.');

        return self::SUCCESS;
    }
}
