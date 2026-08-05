<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-repo deploy policy: rebuild every commit, or only when the built
     * artifact actually changes (P?-?).
     *
     * A byte-identical rebuild — a docs/comment-only commit, or a revert to the
     * live tree — hashes to an image fingerprint the cluster already runs, so it
     * has nothing to deploy. Off (the default) makes such a push a no-op: the
     * running revision already IS that artifact, and a critical box is not
     * churned for a README bump. On restores per-commit revisions for operators
     * who want a distinct <slug>-<sha7> for every push regardless of content.
     *
     * No ->after(): column position is a MySQL-ism Postgres and SQLite ignore.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->boolean('force_rebuild')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('force_rebuild');
        });
    }
};
