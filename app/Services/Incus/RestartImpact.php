<?php

namespace App\Services\Incus;

/**
 * Decides whether a change to a profile's config only takes effect on restart, and
 * for which instance types. Pure and side-effect free so it can be reasoned about
 * and tested on its own.
 *
 * Keys not listed here never require a restart when changed (for example
 * boot.autostart applies at host boot, not by restarting the instance), and
 * description changes are cosmetic. The type mapping is deliberate:
 *
 *  - security.nesting is a container-only concept; changing it needs a container
 *    restart. Virtual machines have no equivalent, so they are never flagged for it.
 *  - limits.cpu / limits.memory apply live on containers but need a restart (or
 *    hotplug) on virtual machines, so only VMs are flagged when they change.
 */
class RestartImpact
{
    /** @var array<string, list<string>> config key => instance types that need a restart */
    private const RESTART_KEYS = [
        'security.nesting' => ['container'],
        'limits.cpu' => ['virtual-machine'],
        'limits.memory' => ['virtual-machine'],
    ];

    /** Keys whose unset value and "false" mean the same thing, so switching between them is not a change. */
    private const BOOLEAN_KEYS = ['security.nesting'];

    /**
     * Reconcile a profile's config before and after an edit against the values its
     * instances are actually running (the "baseline"), returning the keys still out
     * of sync, or null when nothing needs a restart.
     *
     * The baseline for a key is the value inheriting instances hold: one already
     * tracked from an earlier edit, or — for a key that was in sync before this edit
     * — the value it held just before it. A key whose new value returns to its
     * baseline is back in sync and drops out, so editing a change back clears it.
     *
     * @param  array<string, mixed>  $oldConfig
     * @param  array<string, mixed>  $newConfig
     * @param  array<string, string|null>  $existingBaselines  key => tracked baseline
     * @return array{types: list<string>, changes: list<array{key:string,baseline:string,to:string}>}|null
     */
    public static function reconcile(array $oldConfig, array $newConfig, array $existingBaselines = []): ?array
    {
        $types = [];
        $changes = [];

        foreach (self::RESTART_KEYS as $key => $affectedTypes) {
            $old = (string) ($oldConfig[$key] ?? '');
            $new = (string) ($newConfig[$key] ?? '');

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $old = $old === 'true' ? 'true' : 'false';
                $new = $new === 'true' ? 'true' : 'false';
            }

            $baseline = (array_key_exists($key, $existingBaselines) && $existingBaselines[$key] !== null)
                ? (string) $existingBaselines[$key]
                : $old;

            if ($new === $baseline) {
                continue;
            }

            $changes[] = ['key' => $key, 'baseline' => $baseline, 'to' => $new];
            $types = array_merge($types, $affectedTypes);
        }

        if ($changes === []) {
            return null;
        }

        $types = array_values(array_unique($types));
        sort($types);

        return ['types' => $types, 'changes' => $changes];
    }
}
