<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single instance-settings row — kixctl's own first-run and identity state,
 * distinct from IngressSetting (DNS) and Cluster (the Incus connection).
 * `configured_at` is the explicit gate the setup wizard flips when it finishes;
 * the domain and hostname it collects live here too. Mirrors IngressSetting's
 * singleton shape, minus config defaults: nothing is set until the operator runs
 * the wizard, so the row is created empty and stays unconfigured until then.
 */
class InstanceSetting extends Model
{
    protected $fillable = [
        'configured_at', 'domain', 'hostname',
    ];

    protected function casts(): array
    {
        return [
            'configured_at' => 'datetime',
        ];
    }

    /** The singleton row, created empty (unconfigured) if it does not exist yet. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /** Has the first-run wizard been completed? */
    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }
}
