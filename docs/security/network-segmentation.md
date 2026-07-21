# Network segmentation & ingress (P2-4)

_Companion to `incus-scope.md` (the Incus client-cert scope). This file records how
the control plane's admin surface is reached and why WireGuard is **not** a baked-in
dependency. It is the written half of the P2-4 gate — the gate is not clear until this
is recorded._

## The trust boundary

The Laravel/Filament control plane is web-facing **and** holds authority to command the
whole cluster over the Incus REST API. Tenant isolation (VM/container) protects tenants
from each other and from the host; it does **nothing** for the control plane itself. This
is the Coolify risk shape: **containment, not invulnerability.** Therefore the admin
surface must not be casually internet-exposed.

## Segmentation is the operator's ingress choice — not a mechanism kixctl imposes

kixctl integrates with the reverse proxy and network the operator already owns. It never
forces one homelab's topology on a stranger. "Opinionate what I own; integrate with what
the user owns."

- **This install (reference / dogfood): LAN-only behind the operator's Caddy.**
  The panel (`:8001`) and Reverb (`:8080`) bind on `powerhouse` and are reached only
  through the existing Caddy (Incus container `caddy-server`) at
  `https://kixctl.lan.kixago.com`. TLS is real — Let's Encrypt via Cloudflare **DNS-01**,
  so the internal-only name needs no public A record. CrowdSec fronts Caddy. The surface
  is not reachable from the WAN; there is no port-forward to the panel.

- **Public installs:** the operator terminates TLS and gates ingress themselves — an IP
  allowlist, `forward_auth` to an IdP (kanidm/OIDC lines up with the later enterprise
  path), or a tunnel (Cloudflare Tunnel / WireGuard). kixctl's own RBAC (Filament Shield +
  per-verb Livewire-method gates) is the authenticated-**user** layer regardless; ingress
  gating sits in front of it and is the operator's infrastructure, not kixctl's default.

- **WireGuard** is **one optional install-time ingress mode** (a P7 first-boot may offer
  it), never a baseline dependency. It was considered as _the_ mechanism during P2-4 and
  deliberately demoted: forcing a tunnel on an operator who already runs a reverse proxy
  violates "integrate with what the user owns." Segmentation is the requirement; a tunnel
  is just one way to meet it.

## TLS / `forceTLS` — deployment-agnostic by config, not by code

- `config/filament.php` → `broadcasting.echo.forceTLS` **derives from
  `VITE_REVERB_SCHEME`** (`env('VITE_REVERB_SCHEME','http') === 'https'`), not a hardcoded
  boolean. One codebase is correct on localhost `http`, behind a LAN TLS proxy, and on a
  public LE box, with **zero code change** — config carries the deployment difference.

- **Behind a TLS-terminating proxy, trust the proxy.** `bootstrap/app.php` sets
  `trustProxies(at: ['192.168.2.0/24'])` so the app sees the request as `https`
  (`Secure` cookies, correct scheme on Livewire/asset URLs, `request()->secure() === true`).
  Without this the app thinks it is on `http` behind Caddy and Livewire/broadcast URLs go
  out as `http` → mixed-content failures.

- **Client vars are decoupled from server vars.** The PHP→Reverb push stays localhost
  plaintext (`REVERB_HOST=localhost`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`); the
  browser path is the public TLS endpoint (`VITE_REVERB_HOST=kixctl.lan.kixago.com`,
  `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`). Do **not** re-couple them (the
  `.env.example` default couples `VITE_REVERB_*` to `${REVERB_*}` for the all-localhost dev
  case) — setting a single `REVERB_SCHEME=https` behind a proxy breaks the localhost push.

## Caddy vhost (reference)

On `caddy-server`, kixctl needs two upstreams (panel HTTP + Reverb ws); Caddy proxies the
websocket transparently (no manual `Upgrade`/`Connection` headers):

```
"kixctl.lan.kixago.com".extraConfig = ''
  ${dns01}
  reverse_proxy /app/*  http://192.168.2.8:8080
  reverse_proxy /apps/* http://192.168.2.8:8080
  reverse_proxy *       http://192.168.2.8:8001
'';
```

## Result — Coolify-shape risks addressed

- **Transport identity:** least-privilege scoped Incus client cert (`restricted`,
  `projects:[default]`), HTTPS not socket. See `incus-scope.md`.
- **No user input reaches a shell:** reviewed guarantee (everything is structured Incus
  REST calls). See `incus-scope.md`.
- **Admin surface not casually internet-exposed:** ingress is the operator's (LAN-only via
  Caddy here), TLS is real, `forceTLS` proven over `wss` (`101 Switching Protocols`).

With this recorded, the P2-4 gate is clear.
