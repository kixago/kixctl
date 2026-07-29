<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deploy always names the live revision, but a manually-entered ingress record
 * (the Records tab) points straight at an IP:port with no kixctl-managed
 * revision behind it — so live_instance must be optional. Only deploy-driven
 * rows carry it; manual rows leave it null. Routing never depends on it (Caddy
 * proxies to ip:port), it's just the cutover/revert handle when kixctl owns the
 * revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_routes', function (Blueprint $table) {
            $table->string('live_instance')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_routes', function (Blueprint $table) {
            $table->string('live_instance')->nullable(false)->change();
        });
    }
};
