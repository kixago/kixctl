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
    }
}
