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
        'edge' => 'Edge — kixctl runs DNS + its own Caddy',
        'manual' => 'Manual — I run my own DNS',
    ],

    'form' => [
        'provider' => 'Provider',
        'provider_help' => 'Managed is the zero-thought default. Switch to Manual to integrate your own DNS.',
        'zone' => 'Zone',
        'zone_help' => 'Apps are reachable at <app>.<zone>. Internal-only by design.',
        'app_port' => 'App port',
        'app_port_help' => 'The port every app listens on inside its container.',
        'lan_unlocked' => 'Allow LAN reachability',
        'lan_unlocked_help' => 'Off by default: deployed apps are reachable only through kixctl\'s own DNS and edge. Turn on to surface the CoreDNS address and instructions for pointing your own resolver at it — kixctl never changes your resolver, and this alone does not expose anything; you still add the forwarder yourself.',
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

    'records' => [
        'heading' => 'Ingress records',
        'intro' => 'One row per app: the host clients hit, and the container it proxies to. Saving publishes to CoreDNS and the owned Caddy edge — a build console shows progress and the page keeps working.',
        'app' => 'App',
        'app_help' => 'The app key. The host defaults to <app>.<zone>.',
        'host' => 'Host',
        'host_help' => 'Leave blank to use <app>.<zone>. Set it to serve a different name.',
        'ip' => 'Target IP',
        'ip_help' => "The app container's address on kixbr0.",
        'port' => 'Target port',
        'port_help' => 'The port the app listens on inside its container.',
        'instance' => 'Live instance',
        'instance_help' => 'Optional — the revision currently serving this app.',
        'create' => 'Add record',
        'created' => 'Record added.',
        'edit' => 'Edit',
        'updated' => 'Record updated.',
        'delete' => 'Delete',
        'deleted' => 'Record deleted.',
        'delete_confirm' => 'Remove this record and re-publish without it. The app container is not touched.',
        'publish' => 'Publish now',
        'publish_title' => 'Publishing ingress',
        'publish_done' => 'Ingress published.',
        'publish_failed' => 'Publish failed.',
        'dismiss' => 'dismiss',
        'empty_heading' => 'No records yet',
        'empty_description' => 'Add one, or deploy an app — deploys populate this automatically.',
    ],

];
