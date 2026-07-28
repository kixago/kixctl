<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single ingress-settings row (kixctl's own state). GUI-editable; seeded
 * from config/ingress.php on first read so the "leave it alone" user sees the
 * managed defaults already filled in. resetToDefaults() re-seeds from config —
 * that's what "back to defaults" runs before the managed provider re-asserts.
 *
 * Same shape as App\Models\Cluster: typed columns, secret encrypted at rest.
 */
class IngressSetting extends Model
{
    protected $fillable = [
        'provider', 'zone', 'app_port',
        'dns_instance', 'dns_target', 'dns_network', 'dns_refresh', 'record_ttl',
        'byo_endpoint', 'byo_token',
    ];

    protected function casts(): array
    {
        return [
            'app_port' => 'integer',
            'record_ttl' => 'integer',
            'byo_token' => 'encrypted', // AES-256 via APP_KEY, transparent on read
        ];
    }

    /** The singleton row, created from config defaults if it does not exist yet. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], static::defaults());
    }

    /** Reset every managed knob back to config/ingress.php (keeps the row id). */
    public function resetToDefaults(): self
    {
        $this->fill(static::defaults())->save();

        return $this;
    }

    /** The config-derived default attribute set (single source of truth). */
    public static function defaults(): array
    {
        $m = (array) config('ingress.managed', []);

        return [
            'provider' => (string) config('ingress.provider', 'managed'),
            'zone' => (string) config('ingress.zone', 'apps.internal'),
            'app_port' => (int) config('ingress.app_port', 8080),
            'dns_instance' => (string) ($m['instance'] ?? 'kixctl-coredns'),
            'dns_target' => (string) ($m['target'] ?? 'powerhouse'),
            'dns_network' => ($m['network'] ?? '') !== '' ? (string) $m['network'] : null,
            'dns_refresh' => (string) ($m['refresh'] ?? '5s'),
            'record_ttl' => (int) ($m['record_ttl'] ?? 30),
            'byo_endpoint' => null,
            'byo_token' => null,
        ];
    }

    public function isManaged(): bool
    {
        return $this->provider === 'managed';
    }

    /** Fully-qualified host for an app under the configured zone. */
    public function hostFor(string $app): string
    {
        return $app.'.'.trim($this->zone, '.');
    }
}
