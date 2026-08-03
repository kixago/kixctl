# Reaching deployed apps from your LAN

By default a kixctl-deployed app is **internal**: it is reachable only through
kixctl's own CoreDNS resolver and Caddy edge, both of which live on the owned
`kixbr0` bridge. Nothing on your wider network resolves or routes to it until you
choose to expose it. This is deliberate — kixctl never touches your network
unasked. When you turn on **Allow LAN reachability** (Settings → Ingress), the
Updates tab shows the two facts below and this page; the rest is yours to do,
because it lives in equipment kixctl does not own.

## Two separate things have to be true

A name works on your LAN only when both of these hold. They are independent, and
kixctl can help with neither on its own, so it is explicit about both:

1. **Resolvable** — a client that looks up `<app>.<zone>` (default zone
   `apps.internal`) gets an answer. This is decided by *your* resolver, not by
   kixctl. Your laptop asks whatever DNS server your router handed it over DHCP;
   kixctl's CoreDNS is not that server unless you make it so.
2. **Routable** — the client can actually reach the address it got back. Deployed
   apps sit on the NAT'd `kixbr0` bridge, so packets from your LAN need a path
   into that subnet. On a bolt-on install that path is yours to create; on the
   full-OS appliance kixctl owns the host and handles it.

The hint in the Updates tab gives you the resolver address and the zone. What you
do with them depends on the resolver you run.

## If you run your own resolver (Technitium, Unbound, Pi-hole, OPNsense)

Add a **conditional forwarder**: forward only the managed zone (e.g.
`apps.internal`) to the CoreDNS address shown in the hint, and let everything else
resolve as it does today. This is the cleanest option — it exposes only your app
zone and leaves the rest of your DNS untouched.

- **Technitium** — Settings → change the zone's forwarder, or add a Conditional
  Forwarder zone for `apps.internal` pointing at the CoreDNS address.
- **Unbound** — a `forward-zone:` stanza with `name: "apps.internal"` and
  `forward-addr:` set to the CoreDNS address.
- **Pi-hole** — a conditional forwarding entry for the zone and resolver address.
- **OPNsense (Unbound)** — Services → Unbound DNS → Query Forwarding, a domain
  override for the zone to the CoreDNS address.

## If you have a stock router (ISP box, consumer Wi-Fi router)

Most consumer routers cannot do conditional forwarding — they only let you set one
DNS server for the whole LAN. Point that at the CoreDNS address only if kixctl's
resolver is configured to forward everything else upstream (otherwise the rest of
the internet stops resolving). Until that "primary resolver" mode ships, the
supported paths on a stock router are: point a *single device's* DNS at the CoreDNS
address, or reach the app by IP once the routing half below is in place.

## The routing half (bolt-on)

Resolving the name gets you an address on `kixbr0`, which your LAN cannot reach
across the bridge's NAT without a route into that subnet. Provide it the way that
fits your setup — a static route on your router toward the kixctl host, or by
placing the app on a LAN-attached network instead of `kixbr0` (Settings → the
per-instance network override). Note that moving an app off `kixbr0` gives up the
isolation and the in-GUI network control that owning the bridge provides.

## The full-OS install

None of the above is necessary on the kixctl appliance. There kixctl is the host
and owns the network path, so LAN reachability is handled for you rather than left
as an operator step. The bolt-on trades that convenience for running on a cluster
you already have.
