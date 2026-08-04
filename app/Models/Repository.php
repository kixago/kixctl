<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A registered git repository kixctl watches and deploys (P3-6). One row is the
 * whole answer to "how do I fetch and build this repo?": clone URL, branch, build
 * attribute, and the optional per-repo webhook secret. The webhook receiver and
 * the poller both read it and produce the SAME DeployFromPush call, so a pushed
 * deploy and a polled deploy are one deploy reached two ways.
 *
 * SSH-first by design: clone_url carries the auth story. A private repo reached
 * over ssh:// authenticates through the access the host already has (D25), so
 * kixctl stores no deploy key or token at rest — fewer secrets held, which is the
 * direction a future compliance story wants. Only the webhook secret is kept, and
 * it is encrypted under APP_KEY like the cluster certs.
 *
 * slug is generated from the repo leaf on create and is the app's stable name:
 * the DNS host (<slug>.<zone>) and the per-revision instance namespace
 * (<slug>-<sha7>). It is unique, so two repos sharing a leaf cannot collide.
 */
class Repository extends Model
{
    protected $fillable = [
        'full_name', 'slug', 'host', 'clone_url', 'default_branch',
        'build_attr', 'webhook_secret', 'poll_enabled', 'poll_interval',
        'last_seen_sha', 'last_polled_at', 'last_poll_error', 'is_active',
        'pool_id',
    ];

    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted', // AES-256 via APP_KEY, transparent on read
            'poll_enabled' => 'boolean',
            'poll_interval' => 'integer',
            'is_active' => 'boolean',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * Fill slug and host from the repo details when they aren't set explicitly.
     * Keeps the create form to the two fields that matter (full_name, clone_url)
     * without the operator having to hand-name the slug.
     */
    protected static function booted(): void
    {
        static::saving(function (self $repo): void {
            if (blank($repo->slug)) {
                $repo->slug = self::deriveSlug($repo->full_name);
            }
            if (blank($repo->host)) {
                $repo->host = self::deriveHost($repo->clone_url);
            }
        });
    }

    /** The pool this repo promotes with, or null when it promotes individually. */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    /** The app's stable name from "owner/repo": the repo leaf, slugified. */
    public static function deriveSlug(string $fullName): string
    {
        $leaf = (string) Str::of($fullName)->afterLast('/')->slug();

        return $leaf !== '' ? $leaf : 'app';
    }

    /** Display host parsed from a clone URL (ssh://…, https://…, or scp-like). */
    public static function deriveHost(string $cloneUrl): ?string
    {
        $host = parse_url($cloneUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        // scp-like form: git@host:owner/repo.git
        if (preg_match('/^[^@]+@([^:]+):/', $cloneUrl, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** The build attribute for this repo, falling back to the install default. */
    public function buildAttr(): string
    {
        return $this->build_attr !== null && $this->build_attr !== ''
            ? $this->build_attr
            : (string) config('deploy.build.attr', 'default');
    }

    /** True when the repo is fetched over SSH (ssh:// or scp-like git@host:…). */
    public function isSsh(): bool
    {
        return str_starts_with($this->clone_url, 'ssh://')
            || preg_match('/^[^@]+@[^:]+:/', $this->clone_url) === 1;
    }

    /** Whether a per-repo webhook is configured (a secret is set). */
    public function hasWebhook(): bool
    {
        return $this->webhook_secret !== null && $this->webhook_secret !== '';
    }

    /**
     * Whether this repo is due for a poll: never polled, or last poll older than
     * its interval. The scheduler ticks every minute; this gate honours a repo's
     * own cadence.
     */
    public function dueForPoll(): bool
    {
        if ($this->last_polled_at === null) {
            return true;
        }

        return $this->last_polled_at->addSeconds(max(1, (int) $this->poll_interval))->isPast();
    }
}
