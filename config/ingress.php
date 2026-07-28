<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingress — how a deployed <repo>-<sha7> becomes reachable by a stable name
    |--------------------------------------------------------------------------
    |
    | These are DEFAULTS ONLY. The live values are held in the `ingress_settings`
    | singleton (App\Models\IngressSetting), which is GUI-editable and seeded from
    | the values here on first read. "Back to defaults" in the UI re-seeds the row
    | from this file and re-asserts the managed provider. Env is the seed, not the
    | source of truth — same pattern as the clusters table vs INCUS_* env.
    |
    | Providers:
    |   managed  — kixctl auto-provisions a CoreDNS container it owns, serves the
    |              zone, and writes records on every deploy/cutover. Zero user
    |              thought; Caddy stays autonomous. This is the shipped default.
    |   manual   — kixctl records app -> revision -> IP and surfaces the target;
    |              YOU point your own DNS (Technitium, Unbound, …) at it. For the
    |              operator who already has topology and wants to integrate, not
    |              rely. Switching back to `managed` hands control back to kixctl.
    |
    */

    'provider' => env('INGRESS_PROVIDER', 'managed'),

    // The zone kixctl is authoritative for. Apps are reachable at
    // <app>.<zone> (e.g. demo-app.apps.internal). Internal-only by design.
    'zone' => env('INGRESS_ZONE', 'apps.internal'),

    // Port every app listens on inside its container (the deploy base contract).
    'app_port' => (int) env('INGRESS_APP_PORT', 8080),

    'managed' => [
        // The CoreDNS instance kixctl provisions and owns.
        'instance' => env('INGRESS_DNS_INSTANCE', 'kixctl-coredns'),

        // Cluster member the resolver is placed on. Defaults to where apps land
        // so caddy-server reaches it exactly like it reaches the apps today.
        'target' => env('INGRESS_DNS_TARGET', 'powerhouse'),

        // The kixctl-managed network the resolver rides. Empty = the default
        // network row (kixbr0). This is a network KEY (App\Models\Network), not
        // a profile — the resolver's eth0 is placed on it as an explicit NIC
        // device. Set only to pin the resolver to a different managed network.
        'network' => env('INGRESS_DNS_NETWORK', ''),

        // NOTE: the resolver's ROOT-DISK profile is kixctl's OWN profile (`kix`),
        // created out of the box on a pool kixctl auto-resolves — see
        // config/networks.php 'profile'. The NETWORK comes from the instance NIC
        // on the managed bridge, never a profile. This key is retained only for
        // backward reference and is no longer read by CorednsProvisioner.
        'profiles' => ['power'],

        // Local flake kixctl builds the CoreDNS image from (via kixctl-build).
        // Ships in the repo; must import your kixctl-base module (see the flake).
        'flake' => env('INGRESS_DNS_FLAKE', base_path('nix/coredns')),
        'flake_attr' => env('INGRESS_DNS_FLAKE_ATTR', 'coredns'),

        // Writable path inside the resolver where kixctl pushes the zonefile.
        // The Corefile's `file` plugin watches this and reloads on serial change.
        'zonefile_path' => env('INGRESS_ZONEFILE_PATH', '/var/lib/kixctl-dns/apps.db'),

        // How often CoreDNS rescans the zonefile. This is the cutover latency
        // ceiling: a route swing is visible within one refresh interval.
        'refresh' => env('INGRESS_DNS_REFRESH', '5s'),

        // TTL stamped on the A records kixctl writes.
        'record_ttl' => (int) env('INGRESS_RECORD_TTL', 30),
    ],

];
