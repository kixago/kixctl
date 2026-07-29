<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // The self-contained default network (kixbr0). Idempotent; safe to
        // re-run. Also runnable standalone: `php artisan db:seed --class=NetworkSeeder`.
        $this->call(NetworkSeeder::class);

        // The self-contained default profile (kix) — the second owned entity,
        // same locked-default pattern. Idempotent; safe to re-run. Standalone:
        // `php artisan db:seed --class=ProfileSeeder`.
        $this->call(ProfileSeeder::class);
    }
}
