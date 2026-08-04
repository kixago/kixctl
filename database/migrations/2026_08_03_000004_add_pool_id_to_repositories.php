<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single-membership link from a repository to its pool (P3-7, D28). Kept
     * as its own additive migration so the proven repositories table is untouched.
     *
     * Nullable — null means the app promotes individually, exactly as today — and
     * nullOnDelete, so deleting a pool un-pools its members and never deletes
     * them. Removing a grouping is not a destructive act on the apps in it.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->foreignId('pool_id')
                ->nullable()
                ->constrained('pools')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pool_id');
        });
    }
};
