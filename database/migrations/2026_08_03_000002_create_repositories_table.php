<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One registered git repository kixctl watches and deploys — the record that
     * retires the single global DEPLOY_BUILD_ATTR / FORGEJO_WEBHOOK_SECRET and
     * makes push-to-deploy real for arbitrary repos across any host (P3-6).
     *
     * The row is the source of truth for how to fetch and build a repo: the
     * webhook receiver and the commit poller both resolve clone_url, branch,
     * build_attr and the per-repo webhook secret from HERE, and hand identical
     * arguments to DeployFromPush — so a webhook push and a polled push are the
     * same deploy by two triggers.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();

            // "owner/repo" as the host reports it, e.g. "kixago/demo-app". The
            // stable identity a webhook payload names itself by.
            $table->string('full_name')->unique();

            // The app's stable name: the DNS host (<slug>.<zone>) and the
            // per-revision instance namespace (<slug>-<sha7>). Unique so two repos
            // that share a leaf ("demo-app" on two hosts) can't collide on either.
            $table->string('slug')->unique();

            // Display host derived from clone_url (git.lan.kixago.com, github.com…).
            $table->string('host')->nullable();

            // The clone URL kixctl fetches from — SSH-first (ssh://git@host:port/
            // owner/repo.git) or public https. This ONE value is used for both the
            // ls-remote poll and the pinned nix build (git+<clone_url>?rev=<sha>),
            // so SSH access the host already has is all a private repo needs.
            $table->string('clone_url');

            // Branch to deploy. Null = resolve the remote's HEAD symref on first
            // poll and record it here.
            $table->string('default_branch')->nullable();

            // The nixosConfigurations attribute the builder targets for this repo.
            // Null = fall back to config('deploy.build.attr'). Per-repo now, not
            // one global for the whole install.
            $table->string('build_attr')->nullable();

            // Per-repo webhook HMAC secret (encrypted at rest via APP_KEY, same as
            // the cluster certs). Null/blank = this repo has no webhook; the poller
            // is its only trigger. An unconfigured secret never opens the endpoint.
            $table->text('webhook_secret')->nullable();

            // Poller controls. interval is the minimum seconds between polls; the
            // schedule ticks every minute and honours this per repo.
            $table->boolean('poll_enabled')->default(true);
            $table->unsignedInteger('poll_interval')->default(60);

            // Poll bookkeeping: the last commit we saw (dedupe guard so an in-flight
            // or failed build isn't re-dispatched every tick), when we last looked,
            // and the last poll error to surface in the UI instead of a silent stall.
            $table->string('last_seen_sha')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_poll_error')->nullable();

            // Soft on/off without deleting the row (and its config/history).
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
