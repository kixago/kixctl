<?php

namespace App\Models;

use App\Services\Incus\RestartImpact;
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
     * The change list decoded from the raw stored JSON rather than the attribute
     * cast. The cast proved unreliable to read back mid-request on this stack, so we
     * decode the raw column value, which is always the source of truth.
     *
     * @return array<int, array{key:string,baseline:string,to:string}>
     */
    public function decodedChanges(): array
    {
        $raw = $this->getRawOriginal('changes');

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Reconcile a profile's config after an edit against what its instances run.
     * Keys still out of sync (re)raise the flag; keys edited back to their baseline
     * drop out, and when none remain the flag is deleted — so undoing a change before
     * a restart clears the warning. The timestamp only advances when the out-of-sync
     * set actually changes, so an unrelated edit doesn't re-flag already-restarted
     * instances. Resolution per instance still runs through last_used_at.
     *
     * @param  array<string, mixed>  $oldConfig
     * @param  array<string, mixed>  $newConfig
     */
    public static function sync(string $clusterKey, string $profileName, array $oldConfig, array $newConfig): void
    {
        $existing = static::query()
            ->where('cluster_key', $clusterKey)
            ->where('profile_name', $profileName)
            ->first();

        $baselines = [];
        foreach ($existing?->decodedChanges() ?? [] as $change) {
            if (is_array($change) && isset($change['key'])) {
                $baselines[$change['key']] = $change['baseline'] ?? null;
            }
        }

        $result = RestartImpact::reconcile($oldConfig, $newConfig, $baselines);

        if ($result === null) {
            $existing?->delete();

            return;
        }

        $bump = ! $existing || $existing->decodedChanges() != $result['changes'];

        static::updateOrCreate(
            ['cluster_key' => $clusterKey, 'profile_name' => $profileName],
            [
                'affected_types' => $result['types'],
                'changes' => $result['changes'],
                'flagged_at' => $bump ? now() : $existing->flagged_at,
            ],
        );
    }

    /** @return Collection<int, static> */
    public static function forCluster(string $clusterKey): Collection
    {
        return static::query()->where('cluster_key', $clusterKey)->get();
    }
}
