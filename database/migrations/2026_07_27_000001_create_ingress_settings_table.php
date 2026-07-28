<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingress_settings', function (Blueprint $table) {
            $table->id();                                       // singleton: always row 1
            $table->string('provider')->default('managed');    // 'managed' | 'manual'
            $table->string('zone')->default('apps.internal');
            $table->unsignedInteger('app_port')->default(8080);

            // Managed (CoreDNS) knobs — all mirror config/ingress.php defaults.
            $table->string('dns_instance')->default('kixctl-coredns');
            $table->string('dns_target')->default('powerhouse');
            $table->string('dns_network')->nullable();         // null = profile default
            $table->string('dns_refresh')->default('5s');
            $table->unsignedInteger('record_ttl')->default(30);

            // Manual / BYO — optional, only used when the operator integrates their
            // own DNS. Endpoint is informational; any token is a secret at rest.
            $table->string('byo_endpoint')->nullable();
            $table->text('byo_token')->nullable();             // encrypted (model cast)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingress_settings');
    }
};
