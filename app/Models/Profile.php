<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A profile is the second owning thing (the network was the first): kixctl owns
 * its own baseline profile — `kix` — carrying only a root disk on a pool it
 * auto-resolves from the cluster, so a bare box just works and the operator's
 * own `default`/`power` profiles are never touched. The NETWORK for an instance
 * comes from its eth0 NIC on the managed bridge, never from this profile.
 *
 * This is the exact generalization of App\Models\Network: the same typed
 * columns, the same locked-default invariant (kix is undeletable/unrenamable/
 * permanently-managed), the same fallback()/default() resolvers. N3 seeds one
 * row (kix); CRUD over additional profiles rides the ProfileManager engine,
 * identical in shape to NetworkManager.
 */
class Profile extends Model
{
    protected $fillable = [
        'key', 'label', 'managed', 'pool',
        'is_default', 'is_locked', 'description', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'managed' => 'boolean',
            'is_default' => 'boolean',
            'is_locked' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * Model-layer protection for the locked default. Enforced here (not just in
     * the UI) so NO caller — a future CRUD screen, a stray tinker, a job — can
     * delete or rename the guaranteed fallback, or quietly unmanage it. Mirrors
     * Network::booted() exactly.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $profile): void {
            if ($profile->is_locked) {
                throw new \RuntimeException("Profile '{$profile->key}' is a locked default and cannot be deleted.");
            }
        });

        static::updating(function (self $profile): void {
            if (! $profile->is_locked) {
                return;
            }
            if ($profile->isDirty('key')) {
                throw new \RuntimeException("Locked default '{$profile->getOriginal('key')}' cannot be renamed.");
            }
            if ($profile->isDirty('managed') && ! $profile->managed) {
                throw new \RuntimeException("Locked default '{$profile->key}' must stay kixctl-managed.");
            }
            if ($profile->isDirty('is_locked') && ! $profile->is_locked) {
                throw new \RuntimeException("The lock on '{$profile->key}' cannot be removed.");
            }
        });
    }

    /**
     * The profile a new instance inherits when nothing else is chosen: the
     * explicit is_default row, then the locked fallback, then the first managed
     * row, then any row. A caller is never handed null on a seeded install.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::fallback()
            ?? static::query()->where('managed', true)->orderBy('sort')->orderBy('id')->first()
            ?? static::query()->orderBy('sort')->orderBy('id')->first();
    }

    /**
     * The guaranteed "always there" profile — the locked default (kix). This is
     * what a blank/invalid selection resolves to, independent of which row is
     * currently is_default (you may have pointed that at your own profile).
     */
    public static function fallback(): ?self
    {
        return static::query()->where('is_locked', true)->orderBy('sort')->orderBy('id')->first();
    }

    /** Ensure the seeded default row exists, creating it from config if missing. */
    public static function ensureDefault(): self
    {
        $d = static::defaults();

        return static::query()->firstOrCreate(['key' => $d['key']], $d);
    }

    /**
     * Config-derived default attribute set for the seeded `kix` row. Reads the
     * SAME config the CoreDNS provisioner already uses (config/networks.php
     * 'profile'), so there is one source of truth for the owned profile's name
     * and pool — nothing to keep in sync, and CorednsProvisioner::ensureProfile
     * stays untouched.
     */
    public static function defaults(): array
    {
        $p = (array) config('networks.profile', []);

        return [
            'key' => (string) ($p['name'] ?? 'kix'),
            'label' => (string) ($p['label'] ?? 'kixctl profile'),
            'managed' => true,
            // Null pool => auto-resolve from the cluster (parallel to null CIDR).
            'pool' => ($p['pool'] ?? null) ?: null,
            'is_default' => true,
            'is_locked' => true,
            'description' => $p['description']
                ?? 'The default kixctl-owned profile. Carries only a root disk on an auto-resolved pool; the network comes from the instance NIC (kixbr0), never this profile.',
            'sort' => (int) ($p['sort'] ?? 0),
        ];
    }
}
