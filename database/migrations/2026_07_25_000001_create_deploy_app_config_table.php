<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_app_config', function (Blueprint $table) {
            $table->id();
            $table->string('app')->index();   // repo full_name, e.g. "kixago/demo-app"
            $table->string('key');            // env / credential name, e.g. "DATABASE_URL"
            $table->text('value');            // encrypted at rest (see model cast)
            $table->boolean('is_secret')->default(true);
            $table->timestamps();

            $table->unique(['app', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_app_config');
    }
};
