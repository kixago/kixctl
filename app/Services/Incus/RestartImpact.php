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
     * Compare a profile's config before and after an edit. Returns the restart
     * impact, or null when nothing that changed requires a restart.
     *
     * @param  array<string, mixed>  $oldConfig
     * @param  array<string, mixed>  $newConfig
     * @return array{types: list<string>, changes: list<array{key:string,to:string}>}|null
     */
    public static function analyze(array $oldConfig, array $newConfig): ?array
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

            if ($old === $new) {
                continue;
            }

            $changes[] = ['key' => $key, 'to' => $new];
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
