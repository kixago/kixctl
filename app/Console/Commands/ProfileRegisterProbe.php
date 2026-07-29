<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Profiles\ProfileManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for the UNMANAGED profile path — the sibling of
 * kixctl:network-register-probe (which proves the operator's real br0 + its 30
 * instances survive register/unregister untouched). This proves the same for a
 * profile: registering an existing profile as an UNMANAGED reference reaches
 * ZERO createProfile/putProfile/deleteProfile calls, so its definition and its
 * inheritor count are byte-for-byte identical before and after — and
 * deregistering forgets only the kixctl row, never the profile.
 *
 * On a LIVE cluster you should not aim this at a real profile at all. Use --make:
 * the probe creates its OWN throwaway Incus profile, registers THAT as unmanaged,
 * proves zero mutation, deregisters, then deletes the throwaway. No operator
 * profile (default/power/…) is ever read or touched.
 *
 *   php artisan kixctl:profile-register-probe --make            # self-contained (recommended, live-safe)
 *   php artisan kixctl:profile-register-probe --key=default     # against a real profile (READ-ONLY)
 *   php artisan kixctl:profile-register-probe --make --keep     # leave the throwaway + row for inspection
 *
 * NEVER point --key at `power` on this cluster — it is the live profile.
 */
class ProfileRegisterProbe extends Command
{
    protected $signature = 'kixctl:profile-register-probe
        {--key= : Existing profile to register as unmanaged (omit with --make)}
        {--make : Create a throwaway Incus profile to register, then delete it (live-safe)}
        {--keep : Do not deregister after verifying}';

    protected $description = 'Prove registering a profile as unmanaged never mutates it (safety test)';

    public function handle(ProfileManager $manager, IncusClient $incus, ClusterRegistry $registry): int
    {
        $make = (bool) $this->option('make');
        $key = (string) ($this->option('key') ?: ($make ? 'kixregprobe0' : ''));
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        if ($key === '') {
            $this->error('Give --key=<profile> to register a real profile (READ-ONLY), or --make for a self-contained live-safe run.');

            return self::FAILURE;
        }
        if (strtolower($key) === 'power' && ! $make) {
            $this->error("Refusing to probe 'power' — it is the live profile on this cluster. Use --make instead.");

            return self::FAILURE;
        }

        if (Profile::query()->where('key', $key)->exists()) {
            $this->error("'{$key}' is already registered as a kixctl row. Deregister it first or pick another --key.");

            return self::FAILURE;
        }

        // --make: stand up a throwaway Incus profile to register against, so no
        // operator profile is involved at all. Created directly via IncusClient
        // (NOT ProfileManager) so it is unmistakably "external" infra from the
        // register path's point of view. Torn down at the very end.
        if ($make) {
            if ($incus->profileExists($cluster, $key)) {
                $this->error("Throwaway '{$key}' already exists on the cluster. Pick another --key or remove it first.");

                return self::FAILURE;
            }
            $this->info("[--make] Creating throwaway Incus profile '{$key}'…");
            $incus->createProfile(
                $cluster,
                $key,
                devices: [],
                config: ['user.kixctl-probe' => 'throwaway'],
                description: 'kixctl register-probe throwaway — safe to delete',
            );
        } elseif (! $incus->profileExists($cluster, $key)) {
            $this->error("No profile named '{$key}' on the cluster to register.");

            return self::FAILURE;
        }

        // Fingerprint the profile BEFORE we touch anything.
        $before = $incus->profile($cluster, $key);
        $beforeSig = $this->signature($before);
        $beforeUsed = is_countable($before['used_by'] ?? null) ? count($before['used_by']) : 0;
        $this->info("Before: '{$key}'  devices=".implode(',', array_keys($before['devices'] ?? []))."  used_by={$beforeUsed}");

        $this->info("Registering '{$key}' as an UNMANAGED reference…");
        $profile = $manager->register([
            'key' => $key,
            'label' => "reference {$key}",
        ]);

        if ($profile->managed) {
            $this->error('Registered row is managed=true — it should be a reference, never managed.');

            return $this->teardown($incus, $cluster, $key, $make);
        }

        // Fingerprint AFTER register — must be byte-for-byte identical.
        $after = $incus->profile($cluster, $key);
        if ($this->signature($after) !== $beforeSig) {
            $this->error('The profile CHANGED after register — register mutated it. STOP.');
            $this->line('  before: '.$beforeSig);
            $this->line('  after:  '.$this->signature($after));

            return $this->teardown($incus, $cluster, $key, $make);
        }
        $afterUsed = is_countable($after['used_by'] ?? null) ? count($after['used_by']) : 0;
        if ($afterUsed !== $beforeUsed) {
            $this->error("Inheritor count changed ({$beforeUsed} -> {$afterUsed}) — register disturbed instances. STOP.");

            return $this->teardown($incus, $cluster, $key, $make);
        }
        $this->info("After register: identical definition, used_by still {$afterUsed}. Zero mutation confirmed.");

        // Prove update() on an unmanaged row still never touches Incus.
        $this->info('Editing the unmanaged row label (must stay row-only)…');
        $manager->update($profile, ['label' => 'renamed reference', 'description' => 'row-only edit']);
        if ($this->signature($incus->profile($cluster, $key)) !== $beforeSig) {
            $this->error('Editing the unmanaged row mutated the profile. STOP.');

            return $this->teardown($incus, $cluster, $key, $make);
        }
        $this->info('Unmanaged edit stayed row-only. Good.');

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving the '{$key}' reference row".($make ? ' and throwaway profile' : '').' in place.');

            return self::SUCCESS;
        }

        $this->info("Deregistering '{$key}' (forget the row; profile untouched)…");
        $manager->delete($profile);

        if (! $incus->profileExists($cluster, $key)) {
            $this->error('Deregister DELETED the profile — it should only forget the row. STOP.');

            return self::FAILURE;
        }
        if (Profile::query()->where('key', $key)->exists()) {
            $this->error('Deregister returned but the row remains.');

            return $this->teardown($incus, $cluster, $key, $make);
        }
        if ($this->signature($incus->profile($cluster, $key)) !== $beforeSig) {
            $this->error('The profile changed after deregister. STOP.');

            return $this->teardown($incus, $cluster, $key, $make);
        }

        $this->info("Clean: '{$key}' registered, edited and deregistered with the profile untouched throughout. Proven.");

        // Remove the throwaway last (only in --make mode).
        return $this->teardown($incus, $cluster, $key, $make, success: true);
    }

    /**
     * Delete the throwaway profile in --make mode. In --key mode this never
     * deletes anything (the operator's profile is left exactly as found).
     */
    private function teardown(IncusClient $incus, $cluster, string $key, bool $make, bool $success = false): int
    {
        if ($make) {
            try {
                if ($incus->profileExists($cluster, $key)) {
                    $incus->deleteProfile($cluster, $key);
                    $this->info("[--make] Removed throwaway profile '{$key}'.");
                }
            } catch (\Throwable $e) {
                $this->warn("[--make] Could not remove throwaway '{$key}': {$e->getMessage()} — delete it by hand.");
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A stable string fingerprint of the parts of a profile that must never move
     * when kixctl merely references it: description, config and devices.
     *
     * @param  array<string,mixed>  $p
     */
    private function signature(array $p): string
    {
        $subset = [
            'description' => $p['description'] ?? '',
            'config' => $p['config'] ?? [],
            'devices' => $p['devices'] ?? [],
        ];

        return (string) json_encode($subset, JSON_UNESCAPED_SLASHES);
    }
}
