<?php

namespace Database\Seeders;

use App\Models\Profile;
use Illuminate\Database\Seeder;

/**
 * Seeds the one default profile — kix — from config/networks.php ('profile').
 * Idempotent: re-running keeps the row's id and re-asserts the config defaults.
 * The exact sibling of NetworkSeeder. This seeds only the metadata ROW; the real
 * Incus profile is created eagerly by ProfileManager on a UI create, or lazily
 * by CorednsProvisioner::ensureProfile on first provision (both idempotent), so
 * an existing `kix` profile is simply adopted, never recreated.
 *
 * Runnable standalone: `php artisan db:seed --class=ProfileSeeder`.
 */
class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        $d = Profile::defaults();

        Profile::updateOrCreate(['key' => $d['key']], $d);
    }
}
