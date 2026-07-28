<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per app: the revision that is currently LIVE and the address it
     * resolves to. This is the routing source of truth the ingress providers
     * render into DNS (managed) or surface for the operator (manual). Cutover /
     * revert (later slices) are just updates to `live_instance` here, followed
     * by a provider re-publish — no Caddy config touched, ever.
     */
    public function up(): void
    {
        Schema::create('app_routes', function (Blueprint $table) {
            $table->id();
            $table->string('app')->unique();          // repo leaf, e.g. "demo-app"
            $table->string('host');                   // fqdn, e.g. "demo-app.apps.internal"
            $table->string('live_instance');          // "<app>-<sha7>"
            $table->string('ip')->nullable();         // last resolved IPv4 of live_instance
            $table->unsignedInteger('port')->default(8080);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_routes');
    }
};
