# Licensing

kixctl is **open-core, dual-licensed**: the full control plane is released under the
**GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)**, and a **commercial
license** is available for organizations the AGPL doesn't fit. The canonical, legally binding
text is in [`LICENSE`](LICENSE); this file explains what that means in plain terms.

> This is an explanation, not legal advice. Confirm the specifics with your own counsel before
> relying on them commercially.

## What you can do under the AGPL, for free

kixctl is **fully capable under the AGPL at no cost**. Every lifecycle, backup, snapshot,
resource, and deploy verb works on a self-hosted install. There is no "community edition" that
has had features removed to push you toward paying — the free tier is capped by *scale and time*,
never by *capability*. Run it on your own hardware, for your own fleet or your customers', modify
it, and share your changes: that is the AGPL working as intended.

The one obligation that matters is AGPL **section 13, "Remote Network Interaction."** Because
kixctl is network server software, if you run a *modified* version and let others interact with it
over a network, you must offer those users the complete corresponding source of your modification —
including any change you made. Running the unmodified project imposes no such duty. Modifying it
for your own internal use and never offering it to others as a service imposes no such duty. The
obligation attaches specifically to *offering a modified kixctl to others over a network*.

## When you need a commercial license

You need a commercial license only when the AGPL's terms don't fit your intent — most commonly:

- You want to **offer kixctl (or a modified kixctl) as a hosted service** to third parties **without**
  publishing your modifications, which AGPL §13 would otherwise require.
- Your organization's policy or a customer's contract **forbids AGPL-licensed code** in the delivered
  stack, and you need different terms to use kixctl at all.
- You want to **embed kixctl in a proprietary product** on terms incompatible with copyleft.

The commercial license removes the AGPL's source-disclosure obligation under negotiated terms. It
does **not** take anything away from everyone else: the public AGPL source remains, and every other
user keeps their AGPL rights. Buying a commercial license is a way to be relieved of one specific
obligation for your own distribution, not a way to close the project.

To ask about a commercial license, contact **licensing@kixago.com**.

## Why this structure (and not something more restrictive)

The choice of AGPL — rather than a source-available license like BSL or SSPL — is deliberate and
tied to what kixctl claims to be. kixctl is a control plane that holds authority over your whole
cluster; the entire trust argument is *"verifiable because open."* A look-but-don't-touch or
source-available license would undercut that: an operator can't fully trust an orchestration layer
they aren't free to inspect, run, and fork. The AGPL keeps kixctl genuinely open — an OSI-approved,
FSF-approved free software license — while §13 is precisely the clause that makes a *commercial*
re-host publish their changes or come talk to us. That is the honest form of open-core: the money
comes from scale, convenience, and support, not from gating the software's capability.

## Paid capabilities and the free/paid line

The paid tiers are about **scale and operational convenience**, never a capability the free tier
lacks on a single cluster:

- **Fleet scale** — managing clusters beyond the free cap from one pane (the MSP / multi-customer
  operator).
- **Observability ceiling** — long retention, cross-cluster log search, shipping and alerting
  (the operator with an SLA, not the hobbyist).
- **Enterprise governance** (later) — per-user SSO/OIDC, Incus-native fine-grained authorization,
  and audit, sold as a compliance requirement.
- **Managed/hosted state** (later) — an opt-in convenience for teams who want kixctl to run their
  database for them, never the only path and never a capability gate.

Paid allowances are carried in an **offline, signed license file** verified locally against an
embedded public key. There is **no mandatory phone-home**: verification never requires a network
call, so an air-gapped install works exactly like a connected one, and a license problem degrades
gracefully to the free tier rather than disabling the app. On an open security tool, a
callback-to-function would contradict the whole trust posture — so we don't build one.

## Contributing

Contributions are accepted under the same AGPL-3.0-or-later terms as the project. A Contributor
License Agreement (CLA) or Developer Certificate of Origin (DCO) may be required so that the
commercial-license option above can continue to be offered on contributed code; see
`CONTRIBUTING.md` when it lands, or ask before opening a substantial pull request.
