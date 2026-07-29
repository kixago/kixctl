<?php

namespace App\Services\Ingress;

/**
 * Renders a Caddyfile from the current app routes — the reverse-proxy analog of
 * ZoneFile (which renders the CoreDNS zone). kixctl pushes the result into the
 * owned Caddy edge via the Incus files API, and caddy --watch graceful-reloads
 * it. "Data, not config": no site blocks ever live in the nix flake; every route
 * is derived here from app_routes and re-asserted on every ensure().
 *
 * Each route becomes an `http://<host>` site (explicit http:// so caddy serves
 * plain HTTP on :80 and never attempts auto-HTTPS/ACME for the internal zone),
 * reverse-proxying to the app container's <ip>:<port>. A global `auto_https off`
 * is belt-and-suspenders for the same reason.
 */
class CaddyfileRenderer
{
    /**
     * @param  list<array{host:string, ip:string, port:int|string}>  $routes
     */
    public static function build(array $routes): string
    {
        $lines = [];
        $lines[] = '# Managed by kixctl — rendered from app_routes and pushed via the Incus';
        $lines[] = '# files API. Do not edit by hand: caddy --watch reloads this on every push,';
        $lines[] = '# and kixctl re-asserts it from Postgres on every ensure().';
        $lines[] = '';
        $lines[] = '{';
        $lines[] = "\tauto_https off";
        $lines[] = '}';
        $lines[] = '';

        $valid = array_values(array_filter(
            $routes,
            static fn (array $r): bool => ($r['host'] ?? '') !== '' && ($r['ip'] ?? '') !== '' && (int) ($r['port'] ?? 0) > 0,
        ));

        if ($valid === []) {
            // A valid edge that serves nothing meaningful yet — keeps caddy happy
            // and makes "no routes" observably distinct from a broken config.
            $lines[] = ':80 {';
            $lines[] = "\trespond \"kixctl-caddy: no routes yet\" 200";
            $lines[] = '}';

            return implode("\n", $lines)."\n";
        }

        foreach ($valid as $r) {
            $host = self::sanitizeHost((string) $r['host']);
            $upstream = self::sanitizeUpstream((string) $r['ip'], (int) $r['port']);

            $lines[] = "http://{$host} {";
            $lines[] = "\treverse_proxy {$upstream}";
            $lines[] = '}';
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Guard a host label before it becomes a Caddyfile site address. A stray
     * brace or whitespace could break the block structure, so we allow only the
     * characters a DNS host legitimately uses.
     */
    private static function sanitizeHost(string $host): string
    {
        $host = trim($host);
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            throw new \InvalidArgumentException("Unsafe ingress host: {$host}");
        }

        return $host;
    }

    /** Guard the upstream address (ipv4[:port]) the same way. */
    private static function sanitizeUpstream(string $ip, int $port): string
    {
        if (! preg_match('/^[0-9.]+$/', $ip)) {
            throw new \InvalidArgumentException("Unsafe upstream ip: {$ip}");
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Upstream port out of range: {$port}");
        }

        return "{$ip}:{$port}";
    }
}
