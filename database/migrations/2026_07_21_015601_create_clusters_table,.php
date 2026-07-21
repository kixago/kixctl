<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();            // stable slug: 'local', 'acme'
            $table->string('label');                    // human name shown in the UI
            $table->string('driver')->default('https'); // 'socket' | 'https'
            $table->string('url')->nullable();          // https endpoint
            $table->string('socket')->nullable();       // unix socket path (socket driver)
            $table->text('client_cert')->nullable();    // PEM — encrypted at rest (model cast)
            $table->text('client_key')->nullable();     // PEM — encrypted at rest (model cast)
            $table->boolean('verify')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};
