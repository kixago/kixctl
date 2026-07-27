# Known gaps — triaged, owned, not up for cold re-litigation

_The unflinching other half of `differentiation.md`. That doc makes the case for the strategy;
this one lists everything Proxmox, Coolify, and the rest do that I don't — so the case always
sits next to a clear-eyed account of the terrain._

**How to read this — and this is aimed at a fresh session as much as at me.** Every item below is a
gap I already know about and have already triaged. None of it is a discovery. Each one is tagged:

- **CLOSING** — on the roadmap, will be built, sequenced deliberately.
- **DEFERRED (on purpose)** — real, chosen to leave open for now, with the reason.
- **NOT MY LANE** — someone else owns this and should; I will not chase it, and "you're missing X"
  about one of these is not a finding, it's a category error.

If a future session's instinct is "after researching, you got X wrong," check this list first. If X
is here, the decision was made with eyes open — engage with the _reasoning_ (here and in
`decisions.md`), don't re-argue it from zero. If you genuinely think the reasoning itself is flawed
— a dependency died, a threat I didn't consider, an actual error — say so plainly and argue with the
stated reasoning. That's welcome. Reacting to a blank space where the reasoning should be is not,
because the reasoning isn't blank; it's written down. The bar for reopening a settled call is a new
fact or a hole in the argument, not unfamiliarity with it.

---

## vs Proxmox

Proxmox won the VMware exodus and deserved to. On a raw hypervisor checklist it is ahead of me and
will stay ahead on the enterprise-fabric axis. That is fine and expected; I do not compete for the
like-for-like ESXi replacement. (See `differentiation.md` for why Proxmox is closer to a component
than a competitor for what I'm actually building.)

- **High availability (fencing, quorum, auto-failover).** _DEFERRED (on purpose)._ Incus can do the
  underlying work; I haven't surfaced or hardened it. It's Phase 4+ territory. My buyer today (homelab,
  small MSP, single-to-few clusters) is not blocked on automatic node-failure failover, and shipping a
  half-baked HA story is worse than an honest "not yet." Revisit when a paying operator's workload
  actually requires it.
- **Surfaced live migration of running guests.** _CLOSING (later)._ Incus supports it; I just haven't
  built the UI/orchestration around it. Natural Phase 4 item. Not a differentiator either way — it's
  table stakes I'll add when the fabric-management surface deepens.
- **Mature backup (scheduled, incremental, dedup, retention; a Proxmox-Backup-Server equivalent).**
  _CLOSING (later) + partly NOT MY LANE._ A real backup story is on the roadmap (P8-adjacent), and
  the state-boundary design already means the _right_ things are what need backing up. But building a
  dedup backup product to rival PBS is its own company; I'll lean on the external-state model and
  proven tools (Postgres backups already run on .46) rather than reinvent PBS.
- **Storage breadth (Ceph, ZFS-over-iSCSI, the plugin matrix).** _NOT MY LANE._ I'm opinionated on
  storage on purpose — btrfs CoW on `powerpool` for instant snapshots is the supported path. Chasing
  the enterprise storage matrix is exactly the "beat Proxmox at being Proxmox" trap. Integrate with
  what the user has where I can; don't try to own every storage backend.
- **Networking depth (SDN, per-guest firewall UI, VLAN/VXLAN management).** _DEFERRED / NOT MY LANE._
  Managed-network CRUD exists (bridge-first, managed-only). Full SDN is not the wedge and not soon.
- **Scale (large clusters), console/noVNC/SPICE stack, vendor support subscription, a decade of
  hardening, and market trust.** _NOT MY LANE (mostly)._ Three lean nodes is the design center, not a
  limitation I'm apologizing for. "Would I run a v0 project as my production hypervisor" is a fair
  objection and the honest answer is "not yet, for most people" — earned over time, not argued away.
  I am not going to out-mature a ten-year-old product this year, and pretending otherwise is how I'd
  lose credibility with the exact audience I want.

**The through-line for the Proxmox column:** almost none of these are things I "should hurry up and
fix." They're the price of not being Proxmox, and not being Proxmox is the point. The one genuinely
worth building (HA, live migration) is sequenced, not forgotten.

---

## vs Coolify / Dokploy

This is the lane where my deploy story competes head-on, and where the gaps are most worth taking
seriously — because here I _am_ trying to win, eventually, on the same field.

- **Instant onboarding / runs on any cheap VPS with zero substrate knowledge.** _DEFERRED — and this
  is THE one to respect._ Coolify is `curl | bash` and running in five minutes on any Ubuntu box. My
  Incus+NixOS substrate is heavier and narrower. This is the single most important gap in the whole
  document, because it's the one that gates adoption. The answer is the **appliance** (boot an image,
  substrate invisible), and that's precisely why the appliance is on the critical path, not the wish
  list. Until the appliance is real, "harder to start than Coolify" is a true and fair criticism I
  accept. Flagging loudly so no future session mistakes this for something I overlooked — it's the
  known central tradeoff of the whole strategy.
- **Huge one-click service catalog (Coolify v4: hundreds of services).** _NOT MY LANE (as a race)._
  I will not try to match a catalog of hundreds of one-click apps. My unit is "your app, immutably
  built and deployed," plus a curated set of first-class data services (the three-tier DB direction).
  Breadth-of-catalog is Coolify's game; depth-of-correctness (immutability, isolation, rollback) is
  mine. Competing on catalog size is a losing, bottomless race.
- **Community and mindshare (Coolify: north of 50k GitHub stars).** _CLOSING — slowly, honestly._
  I'm one person at zero. This closes with a good demo, the build-in-public devlog, and the
  Incus/self-hosted communities — not by pretending I'm further along than I am. It's a real gap and
  the only fix is time and traction.
- **Docker familiarity / "just give it a Dockerfile."** _DEFERRED / by design._ The immutable-NixOS
  path is more opinionated than "point at a Dockerfile," and that opinionation is deliberate (it's
  what buys reproducibility and the rollback story). A future Dockerfile/OCI ingestion path is
  plausible if real users demand it, but it's not the thesis and not soon.
- **Managed backups of app data volumes.** _CLOSING._ Same as the Proxmox backup note — the
  state-boundary design already isolates what needs backing up; the UI/scheduling around it is later.

**The through-line for the Coolify column:** I concede onboarding and catalog _today_, and I win on
isolation, real immutability, auditability, and a secret architecture — the things that matter more
as the stakes rise. The appliance is the bridge that lets me keep the wins without conceding the
onboarding forever. If any single item in this whole doc deserves urgency, it's the appliance, and
it's already sequenced as such.

---

## vs Kamal

- **Dead-simple, zero control plane to run, battle-tested by 37signals.** _NOT MY LANE (as a
  competition)._ Kamal is a CLI for one team pushing a few apps to servers they understand. I'm a
  control plane for many apps across many clusters, some not the operator's own, with roles and a
  real isolation boundary. For Kamal's ideal user, I'd recommend Kamal. These don't compete; a
  screwdriver isn't losing to a workshop. No gap to "close" here — just clarity about who's who.

---

## Internal debt (my own list, unrelated to competitors)

Honest about the house, too. These are mine to fix regardless of what anyone else ships.

- **Credstore hardening** — injected secrets are currently visible in `incus config show` (plaintext
  instance config). _CLOSING, next-ish._ Known, captured in `decisions.md`; the fix (credstore file
  push) is scoped. A non-event for single-operator, real for multi-tenant.
- **`changes` Eloquent poison-column rename to `change_set`.** _CLOSING (before public release)._
  Worked around today; a clean rename kills the class of bug.
- **The parked delete-button UI check** (2 browser observations). _CLOSING._ Small, queued.
- **Instances-page cluster-filter reset bug.** _CLOSING._ Known, one-line fix hypothesized, unproven.
- **License secret off-box; cert rotation** (LAN-only dev cert). _CLOSING before any public release._
- **Multi-tenant isolation at the Incus-project/RBAC level** — real work for the compliance tier, not
  the current single-operator reality. _DEFERRED (on purpose)_, arrives with that buyer.

---

## The standing rule this document exists to enforce

A gap I've consciously chosen to leave open is a position, not a failing. The difference between a
useful startup assessment and a corrosive one is entirely whether the gaps are treated as _decisions
already made_ or _problems newly discovered_. Everything here is the former. Treat it that way, argue
with the reasoning when the reasoning is genuinely weak, and otherwise build forward from where the
last session left off — which is the whole point of writing any of this down.
