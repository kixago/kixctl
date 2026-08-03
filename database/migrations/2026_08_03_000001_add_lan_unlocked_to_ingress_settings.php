<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D26 — the LAN-reachability posture, as one global flag on the ingress
 * singleton. `false` (the default) is internal-by-default: a deployed app is
 * reachable only through kixctl's own CoreDNS + edge, and the Updates tab shows
 * no routing instructions. `true` surfaces the CoreDNS address, the zone, and a
 * link to the docs on pointing a resolver at it. kixctl never edits the
 * operator's resolver either way — this only governs what it advertises.
 *
 * Global-only on purpose: the conditional forwarder an operator adds is
 * zone-wide (all of the managed zone, or nothing), so there is no per-app LAN
 * gate to enforce in the bolt-on. Per-instance enforcement is a Caddy-edge
 * source-IP concern that only becomes real on the appliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingress_settings', function (Blueprint $table) {
            $table->boolean('lan_unlocked')->default(false)->after('app_port');
        });
    }

    public function down(): void
    {
        Schema::table('ingress_settings', function (Blueprint $table) {
            $table->dropColumn('lan_unlocked');
        });
    }
};
