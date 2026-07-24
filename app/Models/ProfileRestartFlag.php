<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A record that editing a profile changed a setting which only applies on restart.
 * Purely advisory — it drives a warning on the Instances page, never an action.
 * One row per (cluster, profile); reading code decides per instance whether the
 * warning still applies by comparing the instance's last_used_at to flagged_at.
 */
class ProfileRestartFlag extends Model
{
    protected $fillable = [
        'cluster_key', 'profile_name', 'affected_types', 'changes', 'flagged_at',
    ];

    protected function casts(): array
    {
        return [
            'affected_types' => 'array',
            'changes' => 'array',
            'flagged_at' => 'datetime',
        ];
    }

    /**
     * Record or clear the flag for a profile after an edit. A null impact means the
     * edit needs no restart, so any existing flag is removed — resolving a change by
     * editing it back also clears the warning.
     *
     * @param  array{types: list<string>, changes: list<array{key:string,to:string}>}|null  $impact
     */
    public static function sync(string $clusterKey, string $profileName, ?array $impact): void
    {
        if ($impact === null) {
            static::query()
                ->where('cluster_key', $clusterKey)
                ->where('profile_name', $profileName)
                ->delete();

            return;
        }

        static::updateOrCreate(
            ['cluster_key' => $clusterKey, 'profile_name' => $profileName],
            [
                'affected_types' => $impact['types'],
                'changes' => $impact['changes'],
                'flagged_at' => now(),
            ],
        );
    }

    /** @return Collection<int, static> */
    public static function forCluster(string $clusterKey): Collection
    {
        return static::query()->where('cluster_key', $clusterKey)->get();
    }
}
