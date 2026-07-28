<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->id();

            // `key` is BOTH the slug and the Incus managed-network name (e.g.
            // kixbr0). The `kix` prefix is convention, not required.
            $table->string('key')->unique();
            $table->string('label');

            // true  = kixctl created/owns it (created via IncusClient::createNetwork).
            // false = a reference to a network the user already runs (incusbr0, …)
            //         so instances can target it. kixctl NEVER mutates managed=false.
            $table->boolean('managed')->default(true);

            // Null = Incus auto-assigns an unused private subnet (the default,
            // guaranteed non-clashing). Set explicitly to pin address + prefix,
            // e.g. 10.201.5.1/24, 172.16.0.1/23, 192.168.100.1/24.
            $table->string('ipv4_cidr')->nullable();

            $table->boolean('ipv4_nat')->default(true);   // internet egress via NAT
            $table->boolean('ipv4_dhcp')->default(true);  // managed DHCP on the bridge

            // Inter-network posture (wired to Incus network ACLs in N4). Coarse
            // preset front today; becomes "presets + custom" later, additively.
            $table->string('isolation')->default('open'); // open|egress_only|ingress_only|isolated

            // Exactly one row is true. New instances inherit this network.
            // This CAN move (point the default at your own bridge) — the LOCKED
            // row below is the guaranteed fallback, not necessarily the default.
            $table->boolean('is_default')->default(false);

            // A locked row can never be deleted or renamed and always stays
            // kixctl-managed. This is the "always there" guarantee: kixbr0 is
            // seeded locked, so it is the fallback whenever a selection is blank
            // — even if you later point is_default at a network of your own.
            $table->boolean('is_locked')->default(false);

            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('networks');
    }
};
