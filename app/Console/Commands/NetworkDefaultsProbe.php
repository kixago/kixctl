<?php

namespace App\Console\Commands;

use App\Services\Networks\NetworkManager;
use Illuminate\Console\Command;

/**
 * ISOLATION HARNESS for back-to-defaults — a DRY RUN that shows exactly what a
 * reset would remove and keep, and asserts the two safety invariants: the locked
 * default (kixbr0) and every unmanaged reference (br0, br28, …) are NEVER in the
 * removal set. Changes nothing — run it before clicking the real Back-to-defaults
 * to see the plan first.
 *
 *   php artisan kixctl:network-defaults-probe
 */
class NetworkDefaultsProbe extends Command
{
    protected $signature = 'kixctl:network-defaults-probe';

    protected $description = 'Dry-run back-to-defaults: show the plan, prove locked + unmanaged are excluded';

    public function handle(NetworkManager $manager): int
    {
        $plan = $manager->backToDefaults(dryRun: true);

        $this->info('Back-to-defaults plan (DRY RUN — nothing changed):');
        $this->line('  Would REMOVE (kixctl-managed extras): '.($plan['removed'] ? implode(', ', $plan['removed']) : '(none)'));
        $this->line('  Would KEEP locked default:            '.$plan['kept_locked']);
        $this->line('  Would KEEP unmanaged references:      '.($plan['kept_unmanaged'] ? implode(', ', $plan['kept_unmanaged']) : '(none)'));
        $this->line('  Would set default back to:            '.$plan['kept_locked']);
        $this->newLine();

        $ok = true;

        if (in_array($plan['kept_locked'], $plan['removed'], true)) {
            $this->error("Locked default '{$plan['kept_locked']}' is in the removal list — must NEVER happen.");
            $ok = false;
        }
        foreach ($plan['kept_unmanaged'] as $ref) {
            if (in_array($ref, $plan['removed'], true)) {
                $this->error("Unmanaged reference '{$ref}' is in the removal list — must NEVER happen.");
                $ok = false;
            }
        }

        if ($ok) {
            $this->info('Safe: the locked default and all unmanaged references are excluded from reset.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
