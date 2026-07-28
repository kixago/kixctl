<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Networks — the network is the owning thing; kixctl owns its own by default
    |--------------------------------------------------------------------------
    |
    | These are DEFAULTS ONLY. The live values are rows in the `networks` table
    | (App\Models\Network), seeded from here on install (NetworkSeeder). Env is
    | the seed, not the source of truth — same pattern as ingress + clusters.
    |
    | The principle: kixctl creates and owns kixbr0 — its own subnet, DHCP and
    | NAT from Incus — so a bare box just works and a power user's existing fleet
    | is never touched. NEVER bake the operator's own topology into a default.
    |
    */

    // The one seeded default network. is_default => every new instance inherits
    // it. Null CIDR => Incus auto-assigns an unused private subnet (never clashes).
    'default' => [
        'key' => env('KIXCTL_DEFAULT_NETWORK', 'kixbr0'),  // slug AND Incus bridge name
        'label' => 'kixctl bridge',
        'managed' => true,
        'ipv4_cidr' => env('KIXCTL_DEFAULT_NETWORK_CIDR', null), // null = auto-subnet
        'ipv4_nat' => true,
        'ipv4_dhcp' => true,
        'isolation' => 'open',
        'is_default' => true,
        'is_locked' => true,   // the "always there" fallback — never deletable/renamable
        'sort' => 0,
    ],

    // kixctl owns its OWN profile — it never borrows the operator's `default`
    // or `power`. The profile carries only a root disk; the NETWORK comes from
    // the instance's eth0 NIC on the managed bridge (kixbr0), not from here.
    // Created out of the box on first provision; safe and idempotent.
    'profile' => [
        // Name of the kixctl-owned profile. `kix` matches the kixbr0 convention.
        'name' => env('KIXCTL_PROFILE', 'kix'),

        // Storage pool for kixctl's root disks. Null = auto-resolve from the
        // cluster (a single pool is used as-is; otherwise one named `default`;
        // otherwise the first). You never have to set this — kixctl asks Incus.
        // Set it only to pin a specific pool by name.
        'pool' => env('KIXCTL_POOL', null) ?: null,
    ],

];
