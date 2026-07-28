<?php

return [

    'title' => 'Ingress',

    'saved' => 'Ingress settings saved.',
    'reset' => 'Reset to defaults — kixctl is managing ingress again.',
    'sync_failed' => 'Saved, but re-publishing DNS failed',

    'section' => [
        'general' => 'Ingress',
        'general_help' => 'How a deployed revision becomes reachable by a stable name.',
        'managed' => 'Managed DNS (CoreDNS)',
        'managed_help' => 'kixctl provisions and owns a CoreDNS resolver and writes records on every deploy. Leave these alone unless you know you need to change them.',
        'manual' => 'Bring your own DNS',
        'manual_help' => "kixctl records each app's target below but writes no DNS — you point your own resolver at it.",
    ],

    'provider' => [
        'managed' => 'Managed — kixctl runs DNS for me',
        'manual' => 'Manual — I run my own DNS',
    ],

    'form' => [
        'provider' => 'Provider',
        'provider_help' => 'Managed is the zero-thought default. Switch to Manual to integrate your own DNS.',
        'zone' => 'Zone',
        'zone_help' => 'Apps are reachable at <app>.<zone>. Internal-only by design.',
        'app_port' => 'App port',
        'app_port_help' => 'The port every app listens on inside its container.',
        'dns_instance' => 'Resolver instance name',
        'dns_target' => 'Cluster member',
        'dns_target_help' => 'Where the resolver runs. Defaults to where your apps land, so Caddy reaches it the same way.',
        'dns_network' => 'Network',
        'dns_network_placeholder' => '(profile default — same bridge as your apps)',
        'dns_network_help' => 'Leave blank unless your apps run on a network Caddy reaches differently.',
        'dns_refresh' => 'Reload interval',
        'dns_refresh_help' => 'How fast a route change takes effect (CoreDNS zonefile rescan). Also the cutover latency ceiling.',
        'record_ttl' => 'Record TTL (seconds)',
        'byo_endpoint' => 'Your DNS endpoint',
        'byo_endpoint_placeholder' => 'e.g. https://technitium.lan/api',
        'byo_endpoint_help' => 'Informational — where you manage records. kixctl does not write here.',
        'byo_token' => 'API token',
        'byo_token_help' => 'Stored encrypted at rest. Optional; only if you later automate your own DNS.',
    ],

    'action' => [
        'save' => 'Save',
        'defaults' => 'Back to defaults',
        'defaults_confirm' => 'Reset every ingress setting to the shipped defaults and hand DNS back to kixctl. Your own-DNS settings will be cleared.',
    ],

    'status' => [
        'heading' => 'Status',
    ],

];
