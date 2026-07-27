# kixctl

**The control plane for infrastructure you own — an Incus fabric *and* an immutable, git-push
application layer, run through one control plane that is built so it cannot escalate its own
privileges.**

*(kixctl, one word, like `systemctl`.)*

kixctl is an opinionated, modern control plane for [Incus](https://linuxcontainers.org/incus/)
clusters — VMs and system containers on your own bare metal. It is aimed at small teams and
operators who run more than one cluster (or run clusters for other people), and who want to
actually *deploy applications* onto that fabric without gluing a second, unrelated tool on top of
it.

> **Status:** active development, pre-release. The push-to-deploy spine is proven end-to-end on a
> real cluster (a `git push` becomes a running, immutable, config-carrying container), but kixctl
> is not yet packaged for one-command install. The architecture below is real and in the tree; the
> polish, the appliance image, and the hosted tiers are on the roadmap. See
> [`docs/architecture.md`](docs/architecture.md) for what exists today.

## Why kixctl exists

Everyone else in this market picked a side. You either get a **virtualization fabric** with no
opinion about how your applications get deployed (Proxmox), or you get an **application deploy
tool** with no opinion about isolation, multi-tenancy, or the fleet underneath it (Coolify,
Dokploy, Kamal). kixctl is the thing sitting in the middle — the fabric and the deploy layer,
through one control plane. That intersection is empty right now.

The backdrop is the post-Broadcom hypervisor migration. Proxmox won the like-for-like ESXi
replacement, and it deserved to — it is free, mature, and has a working import wizard. **kixctl is
not trying to beat Proxmox at being Proxmox.** It is for the layer of that same wave Proxmox leaves
unserved: teams who find Proxmox capable but cluttered, who run several clusters, and who want a
cloud-shaped *deploy* experience on hardware they own.

Full positioning, including honest head-to-heads and where kixctl loses today, is in
[`differentiation.md`](differentiation.md).

## What makes it different

**One control plane, many clusters.** kixctl manages your cluster *and* your customers' clusters
from one pane — the "multi-cluster" that actually matters to an operator, not a single-box UI.

**Immutable, git-push deploys.** You don't mutate a deployment; you stand up a new one. A push to
your Git host builds a NixOS image, imports it over the Incus REST API, and launches it as an
immutable per-revision instance named `<repo>-<sha7>`. Reverting is swinging a route back to a
whole, intact prior revision — not a hoped-for reverse migration.

**A control plane that cannot escalate its own privileges.** kixctl talks to Incus only over a
*restricted* TLS client certificate scoped to one project. If the web tier is ever compromised, the
attacker inherits exactly that scope and no more. kixctl cannot widen its own access — that is an
invariant *and* a selling point. Scope remediation is always the cluster administrator's action,
never something kixctl does to itself.

**Security that falls out of the architecture, not bolted on.** Immutable units that hold no
durable state; per-app secrets encrypted at rest under a sops-guarded key; delivery into containers
via systemd credentials (never baked into an image); a deploy pipeline that never runs as root; and
per-revision auditability that is legible from Incus itself (`volatile.base_image` + the `-<sha7>`
name tell you what is running and what the rollback target is). That is the substrate a HIPAA or
SOC 2 story stands on — and Proxmox has no deployment model, no immutability, and no secret
architecture to point at.

## How it works, briefly

```
git push  ──▶  Forgejo webhook (HMAC-verified)
            ──▶  DeployFromPush job (queued)
            ──▶  kixctl-build: one audited program, no general shell,
                 commit-pinned hermetic `nix build`  ──▶ two image tarballs
            ──▶  import over the Incus REST API (idempotent, aliased by revision)
            ──▶  launch immutable  <repo>-<sha7>  on a chosen cluster member
            ──▶  per-app encrypted config injected as systemd credentials,
                 read by the app as process.env  (carried into every revision)
            ──▶  [next] Caddy route  ──▶  update / cutover / reap / revert
```

The deployed unit holds **no durable state**. State lives in a database it connects to; only the
thin *config* layer (where its state lives, plus secrets) is declared once per app and injected
into every revision — so a fresh revision comes up already knowing where its state is, and an
update feels continuous. Databases follow a three-tier path: bring-your-own DB first, a
kixctl-provisioned DB container next, and an optional managed database later — the same injection
path throughout. Details and the reasoning are in
[`docs/architecture.md`](docs/architecture.md) and [`decisions.md`](decisions.md).

## Stack

Laravel + Livewire + Filament (PHP 8.4) for the control plane; **Postgres is kixctl's own state**
(never a synced copy of Incus). Horizon + Reverb + Valkey for queued, streamed operations. The
**Incus REST API is the only backend** — Laravel never touches the host, and never the raw Incus
socket. The one legitimately host-touching action, `nix build`, is isolated in the audited
`kixctl-build` subsystem. Built images are NixOS, which is what makes atomic upgrade and rollback —
and the eventual bootable appliance — natural rather than bolted on.

## Roadmap shape

The near-term line, in order: documentation (this pass) → credstore hardening (move injected
secrets off plaintext instance config into the container credstore) → the Caddy route → the
update/cutover/reap/revert lifecycle → kixctl-provisioned databases. Beyond that, the **appliance**
is the headline distribution: boot an image, kixctl installs itself, a first-run wizard sets it up,
and Incus is invisible underneath — "a better Proxmox," built on the same image machinery already
proven for container deploys. The full roadmap and current build state live in
[`immutable-deploy-business-plan.md`](immutable-deploy-business-plan.md).

## Documentation map

This repository is written so its docs and Git history are a substrate the project can be
understood and regenerated from:

- **[`docs/architecture.md`](docs/architecture.md)** — the immutable model, the state boundary, the
  secret chain, the build-identity invariant, the layered auth model, the three-tier database
  direction, and the compliance posture.
- **[`docs/security/`](docs/security/)** — the Incus client-cert scope (`incus-scope.md`) and the
  ingress / segmentation posture (`network-segmentation.md`).
- **[`decisions.md`](decisions.md)** — the *why* behind what was built: decisions, alternatives
  weighed, and what was deferred and why.
- **[`differentiation.md`](differentiation.md)** — market and positioning, with honest head-to-heads.
- **[`immutable-deploy-business-plan.md`](immutable-deploy-business-plan.md)** — mission, roadmap,
  and current build state.

## License

kixctl is **dual-licensed: AGPL-3.0-or-later, or a commercial license.** It is fully capable under
the AGPL at no cost — the free tier is capped by scale and time, never by capability. A commercial
license exists for organizations the AGPL doesn't fit (for example, offering a modified kixctl as a
hosted service without publishing their changes). There is no mandatory phone-home; paid allowances
are verified from an offline signed license.

See **[`LICENSE`](LICENSE)** for the binding text and **[`LICENSING.md`](LICENSING.md)** for a plain
explanation of the dual license and the free/paid line.
