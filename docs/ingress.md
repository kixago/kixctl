# Ingress — reachable-by-stable-name (slice D)

A deployed `<repo>-<sha7>` becomes reachable at a stable `<app>.<zone>` name without
ever touching Caddy's config. This is decision **D14** made concrete: a static
wildcard vhost in Caddy plus a **dynamic upstream**, where deploys change *data*
(a DNS zonefile), never Caddy config.

## The rail

```
git push ──▶ DeployFromPush ──▶ IngressManager->publish(app, instance, ip, port)
                                        │
                        (managed provider, the default)
                                        ▼
        upsert app_routes ──▶ render the whole zone ──▶ pushInstanceFile
                                        │                    (0644, into CoreDNS)
                                        ▼
                       CoreDNS `file` plugin reloads on SOA-serial bump (≤ refresh)
                                        ▼
   Caddy  *.apps.internal  ──▶  dynamic a {label}.apps.internal { resolvers <coredns-ip> }
```

- **Managed provider** (default): kixctl provisions a CoreDNS container it owns,
  and every deploy/cutover rewrites one zonefile and pushes it in with the
  `pushInstanceFile` primitive. No restart, no admin API, no Caddy reload.
- **Manual provider**: same `app_routes` data, but kixctl writes no DNS — the
  Ingress settings page surfaces each app's target and you point your own
  resolver (Technitium, Unbound, …) at it. Switching back to *managed* hands
  control to kixctl again ("Back to defaults").

## What kixctl provisions

On the first deploy under the managed provider, kixctl builds the CoreDNS image
from `nix/coredns` (via `kixctl-build`, the same path apps use), imports it, and
launches it as `kixctl-coredns` on the deploy target. See `nix/coredns/flake.nix`
— it must import your `kixctl-base` module (one marked seam).

## Caddy side (apply once on caddy-server, survives `rr`)

This is the only static config, and it never changes per deploy. Add to your
NixOS-managed Caddyfile and `rr`. Replace `<COREDNS_IP>` with the resolver IP the
Ingress settings page shows (stable once provisioned).

```
*.apps.internal {
    tls {
        # wildcard cert via your existing DNS-01 issuer, same as the panel
        dns cloudflare {env.CF_API_TOKEN}
    }
    reverse_proxy {
        dynamic a {
            name {labels.2}.apps.internal
            port 8080
            resolvers <COREDNS_IP>
            refresh 5s
        }
    }
}
```

`{labels.2}` is the app subdomain (the label just left of `apps.internal`).
Adjust the index if your zone has a different depth. Cutover latency is bounded
by `refresh`.

## DeployFromPush hook

Add the publish call after a successful launch (see the message accompanying this
drop — it is a small targeted edit so it does not clobber the credstore change
already in that file):

```php
// after the instance is launched and its IP is known
$ip = $incus->instanceIpv4($cluster, $name);
if ($ip !== null) {
    app(\App\Services\Ingress\IngressManager::class)
        ->publish($this->repository, $name, $ip, (int) config('ingress.app_port', 8080));
}
```

## Notes / boundaries

- **Zone, zonefile path, and reload interval are compiled into the resolver
  image** (the Corefile). Their GUI defaults match the flake. Changing them is an
  "advanced, reprovision the resolver" action, not a hot setting.
- **Cutover / reap / revert** (later slices) are just updates to `app_routes`
  followed by a provider re-publish — no new ingress machinery.
- Records are internal-only (RFC1918 IPs in an internal zone); nothing is
  published to public DNS.
