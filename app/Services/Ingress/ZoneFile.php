<?php

namespace App\Services\Ingress;

/**
 * Renders the CoreDNS zonefile the managed provider pushes into the resolver.
 *
 * The `file` plugin reloads a zone only when the SOA SERIAL changes (verified
 * against CoreDNS docs), so every render stamps a fresh unix-timestamp serial —
 * that is what makes a pushed update actually take effect within CoreDNS's
 * refresh interval. A minimal apex (SOA + NS) makes the zone authoritative; one
 * A record per live app follows.
 */
class ZoneFile
{
    /**
     * @param  array<int,array{name:string,ip:string,ttl:int}>  $records
     *         name is the LABEL only (e.g. "demo-app"), relative to $zone.
     */
    public static function build(string $zone, array $records, int $defaultTtl = 30): string
    {
        $zone = rtrim($zone, '.').'.';
        $serial = time();                 // strictly increasing per write
        $ns = 'ns.'.$zone;
        $admin = 'hostmaster.'.$zone;

        $lines = [];
        $lines[] = '$ORIGIN '.$zone;
        $lines[] = '$TTL '.$defaultTtl;
        $lines[] = '@ IN SOA '.$ns.' '.$admin.' ('
            .$serial.' '   // serial — bumped every render
            .'7200 '       // refresh
            .'3600 '       // retry
            .'1209600 '    // expire
            .$defaultTtl   // minimum / negative-cache TTL
            .')';
        $lines[] = '@ IN NS '.$ns;
        // The apex NS needs an address so the zone is self-contained; point it at
        // the loopback of the resolver itself (never queried for real traffic).
        $lines[] = 'ns IN A 127.0.0.1';

        foreach ($records as $r) {
            $label = trim((string) $r['name']);
            $ip = trim((string) $r['ip']);
            if ($label === '' || $ip === '') {
                continue;
            }
            $ttl = (int) ($r['ttl'] ?? $defaultTtl);
            $lines[] = $label.' '.$ttl.' IN A '.$ip;
        }

        return implode("\n", $lines)."\n";
    }
}
