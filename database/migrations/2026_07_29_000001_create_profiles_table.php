<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `profiles` table is the exact sibling of `networks`: kixctl owns its own
 * baseline profile (`kix` — a root disk on an auto-resolved pool) the same way
 * it owns kixbr0, and the same locked-default invariant guarantees it is always
 * there when a selection is blank. A managed row is one kixctl created via
 * IncusClient::createProfile; an unmanaged row is a reference to a profile the
 * operator already runs (`power`, `default`) that kixctl can target but NEVER
 * mutates — identical to the managed/unmanaged split on networks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();

            // `key` is BOTH the slug and the Incus profile name (e.g. `kix`).
            $table->string('key')->unique();
            $table->string('label');

            // true  = kixctl created/owns it (via IncusClient::createProfile).
            // false = a reference to a profile the operator already runs
            //         (power, default) so instances can target it. kixctl NEVER
            //         mutates managed=false — same guarantee as unmanaged nets.
            $table->boolean('managed')->default(true);

            // The storage pool for this profile's root disk. Null = auto-resolve
            // from the cluster (single pool as-is; else one named `default`; else
            // first by name) — the exact parallel to a network's null CIDR =
            // auto-subnet. Set explicitly to pin a specific pool by name.
            $table->string('pool')->nullable();

            // Exactly one row is true. New instances inherit this profile. This
            // CAN move (point it at your own profile); the LOCKED row below is
            // the guaranteed fallback, not necessarily the default.
            $table->boolean('is_default')->default(false);

            // A locked row can never be deleted or renamed and always stays
            // kixctl-managed. `kix` is seeded locked, so it is the fallback
            // whenever a selection is blank — even if you later point is_default
            // at a profile of your own.
            $table->boolean('is_locked')->default(false);

            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
