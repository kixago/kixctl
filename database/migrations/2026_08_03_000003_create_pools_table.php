<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pool is the unit that promotes together (P3-7, D28): one Update cuts a
     * whole batch of member apps over at once, so an operator running twenty-plus
     * instances isn't clicking Update per app. Single-membership by design — an
     * app belongs to at most one pool — because a pool answers exactly one
     * question, "what promotes together," and that is naturally single-valued.
     *
     * A first-class table rather than a string column on the repository is
     * deliberate: the later gated-auto timer and its health gate (D28, a separate
     * P3-8-class item) attach to THE POOL, and those columns must be able to grow
     * here without a data migration. Kept intentionally minimal now — name,
     * label, timestamps — and it never carries membership that is many-to-many
     * (roles and policy live on their own axis, never folded in here).
     */
    public function up(): void
    {
        Schema::create('pools', function (Blueprint $table) {
            $table->id();

            // The pool's stable identifier, slugified from label on create.
            // Unique, so "promote pool X" names exactly one batch.
            $table->string('name')->unique();

            // Human display name shown in the Pool dropdown and the Pool column.
            $table->string('label');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pools');
    }
};
