<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Advisory state: records that a profile edit changed a setting that only
        // takes effect on restart, so the Instances page can warn (never act) about
        // instances still running the previous definition. One row per profile;
        // cleared when an edit no longer has restart impact. Warnings auto-resolve
        // per instance by comparing the instance's last_used_at to flagged_at.
        Schema::create('profile_restart_flags', function (Blueprint $table) {
            $table->id();
            $table->string('cluster_key');            // ClusterRegistry key
            $table->string('profile_name');           // the edited profile
            $table->json('affected_types');           // instance types the change needs a restart for, e.g. ["container"]
            $table->json('changes');                  // what changed, e.g. [{"key":"security.nesting","to":"true"}]
            $table->timestamp('flagged_at');          // when the restart-requiring edit landed
            $table->timestamps();
            $table->unique(['cluster_key', 'profile_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_restart_flags');
    }
};
