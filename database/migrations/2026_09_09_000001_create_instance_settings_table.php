<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->id();                               // singleton: always row 1

            // The explicit first-run gate: null until the setup wizard completes.
            // Everything downstream keys off this rather than inferring
            // configured-ness from scattered state.
            $table->timestamp('configured_at')->nullable();

            // Identity the wizard collects (populated in 3b). Kept here — this is
            // instance state, distinct from IngressSetting (DNS) and Cluster (Incus).
            $table->string('domain')->nullable();       // panel domain / APP_URL host
            $table->string('hostname')->nullable();     // appliance hostname

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_settings');
    }
};
