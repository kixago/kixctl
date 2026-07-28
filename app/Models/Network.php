<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A network is the owning thing: kixctl creates and owns its own managed bridge
 * (kixbr0) by default — its own subnet, DHCP and NAT — so a bare box just works
 * and a power user's existing fleet is never touched. Every instance kixctl
 * launches rides a kixctl-managed network, never the user's LAN or their
 * existing incusbr0 unless they explicitly register and choose it.
 *
 * Same shape as App\Models\IngressSetting: typed columns, config-seeded defaults,
 * a single source of truth the GUI edits. N1 seeds exactly one row (kixbr0);
 * CRUD over additional networks (kixbr1, workbr0, …) arrives in N2.
 */
class Network extends Model
{
    protected $fillable = [
        'key', 'label', 'managed', 'ipv4_cidr', 'ipv4_nat', 'ipv4_dhcp',
        'isolation', 'is_default', 'is_locked', 'description', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'managed' => 'boolean',
            'ipv4_nat' => 'boolean',
            'ipv4_dhcp' => 'boolean',
            'is_default' => 'boolean',
            'is_locked' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * Model-layer protection for the locked default. Enforced here (not just in
     * the UI) so NO caller — a future CRUD screen, a stray tinker, a job — can
     * delete or rename the guaranteed fallback, or quietly unmanage it.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $network): void {
            if ($network->is_locked) {
                throw new \RuntimeException("Network '{$network->key}' is a locked default and cannot be deleted.");
            }
        });

        static::updating(function (self $network): void {
            if (! $network->is_locked) {
                return;
            }
            if ($network->isDirty('key')) {
                throw new \RuntimeException("Locked default '{$network->getOriginal('key')}' cannot be renamed.");
            }
            if ($network->isDirty('managed') && ! $network->managed) {
                throw new \RuntimeException("Locked default '{$network->key}' must stay kixctl-managed.");
            }
            if ($network->isDirty('is_locked') && ! $network->is_locked) {
                throw new \RuntimeException("The lock on '{$network->key}' cannot be removed.");
            }
        });
    }

    /** The isolation postures (v1). Each becomes a generated Incus ACL in N4. */
    public const ISOLATIONS = ['open', 'egress_only', 'ingress_only', 'isolated'];

    /**
     * The network a new instance inherits when nothing else is chosen: the
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
     * The guaranteed "always there" network — the locked default (kixbr0). This
     * is what a blank/invalid selection resolves to, independent of which row is
     * currently is_default (you may have pointed that at your own bridge).
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

    /** Config-derived default attribute set for the seeded kixbr0 row. */
    public static function defaults(): array
    {
        $d = (array) config('networks.default', []);

        return [
            'key' => (string) ($d['key'] ?? 'kixbr0'),
            'label' => (string) ($d['label'] ?? 'kixctl bridge'),
            'managed' => (bool) ($d['managed'] ?? true),
            // Null CIDR => Incus auto-assigns an unused private subnet.
            'ipv4_cidr' => ($d['ipv4_cidr'] ?? null) ?: null,
            'ipv4_nat' => (bool) ($d['ipv4_nat'] ?? true),
            'ipv4_dhcp' => (bool) ($d['ipv4_dhcp'] ?? true),
            'isolation' => (string) ($d['isolation'] ?? 'open'),
            'is_default' => (bool) ($d['is_default'] ?? true),
            'is_locked' => (bool) ($d['is_locked'] ?? true),
            'description' => $d['description'] ?? 'The default kixctl-managed bridge. Self-contained: its own subnet, DHCP and NAT from Incus, isolated from your LAN and incusbr0.',
            'sort' => (int) ($d['sort'] ?? 0),
        ];
    }

    /**
     * The Incus network config map for IncusClient::createNetwork(). Null CIDR is
     * translated to ipv4.address=auto (Incus picks a random unused subnet); an
     * explicit CIDR is passed verbatim. NAT and DHCP are always stated explicitly
     * rather than relying on Incus defaults, so the row is the source of truth.
     *
     * @return array<string,string>
     */
    public function incusConfig(): array
    {
        return [
            'ipv4.address' => ($this->ipv4_cidr && $this->ipv4_cidr !== '') ? $this->ipv4_cidr : 'auto',
            'ipv4.nat' => $this->ipv4_nat ? 'true' : 'false',
            'ipv4.dhcp' => $this->ipv4_dhcp ? 'true' : 'false',
        ];
    }
}
