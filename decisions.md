# kixctl — Decision Ledger

_The **why** behind what was built, kept deliberately so nothing is lost between sessions.
`prompt.md` is the code-level resume; `immutable-deploy-business-plan.md` is the strategy and
roadmap; this file is the reasoning — the decisions, the alternatives weighed, and what was
deferred and why. Written so a future session (human or AI) can reconstruct full documentation
(README, architecture docs, marketing) from this plus the git history alone._

---

## Session of 2026-07-25 — P3-2 (push-to-deploy) + the state boundary

### What shipped this session (all committed to `kixago/kixctl`)

The **entire push-to-deploy spine, proven end-to-end on the real cluster**: a `git push` to
Forgejo becomes a running, immutable, config-carrying container on `powerhouse`, over the
restricted-cert REST transport, with the one host-touching step (`nix build`) isolated in an
audited subsystem.

- **Slice A — the trigger receiver.** `POST /api/deploy/forgejo` verifies the Forgejo HMAC
  signature, parses the push, and queues `DeployFromPush`. Commit: _"P3-2 slice A: Forgejo push
  webhook receiver (verify + parse + queue DeployFromPush)"_.
- **Slice B — the build.** `DeployFromPush` hands the pushed repo (pinned to the exact commit)
  to the `kixctl-build` subsystem, which runs `nix build` and returns the two image tarballs.
  Commit: _"P3-2 slice B: kixctl-build subsystem + DeployFromPush builds the image (nix build ->
  two tarballs)"_.
- **Slice C — import + launch.** The two tarballs are imported (idempotently, aliased by
  revision) and launched as an immutable `<repo>-<sha7>` instance on a chosen member. Commit:
  _"P3-2 slice C: import + launch immutable per-revision instance (<repo>-<sha7>) on target
  member"_.
- **State boundary — per-app config injection.** A per-app encrypted config store, injected
  into every revision at launch as systemd credentials, consumed as `process.env`. Commit:
  _"P3-2 state boundary: per-app encrypted config store, injected as systemd credentials at
  launch"_.

Proven live: a push to `kixago/demo-app` built `demo-app-<sha7>`, RUNNING on powerhouse with a
DHCP lease, serving HTTP, and a `DATABASE_URL` typed into kixctl's store surfaced as
`process.env.DATABASE_URL` inside a brand-new container — carry-forward demonstrated across
three successive immutable revisions.

---

### Decisions, with reasoning

#### D1 — Pipeline shape: A→D, Caddy wired LAST

**Decision.** Push-to-deploy is built as ordered slices: (A) webhook receiver → (B) build →
(C) import/launch → (D) Caddy route, each proven before the next. Caddy is deliberately last.

**Why.** Caddy routing is the thorniest surface (see D14) and touches a second host. Everything
upstream of it is provable in isolation without any ingress changes, so it de-risks first. The
smallest first cut the operator agreed to was "webhook → build → import/launch, Caddy last."

#### D2 — Webhook auth: Forgejo HMAC signature, verified offline

**Decision.** The webhook authenticates by Forgejo's `X-Forgejo-Signature` (HMAC-SHA256 hex over
the **raw** request body, keyed by a shared secret). Verified by a pure, unit-testable
`WebhookSignature::valid()` using `hash_equals` (constant-time). The secret is operator-chosen,
identical in the Forgejo webhook form and `FORGEJO_WEBHOOK_SECRET` — Forgejo does not generate
it. The route lives on the stateless `api` group (no session, no CSRF); auth is the signature,
not a logged-in user.

**Why.** Verified against current Forgejo docs, not memory. Reading the raw body is mandatory —
re-encoding the parsed array would change bytes and break the HMAC. `hash_equals` over `===`
avoids a timing side-channel. Signature (not the alternative Authorization-header path) is the
standard, and keeps the endpoint machine-to-machine.

#### D3 — Forgejo SSRF allowlist (operational, not code)

**Decision.** Forgejo's `[webhook] ALLOWED_HOST_LIST` defaults to blocking private IPs, so it
refused the LAN target. Fix: `services.forgejo.settings.webhook.ALLOWED_HOST_LIST =
"external,192.168.2.0/24"` (scoped to the cluster `/24`, not `*` or `private`).

**Why.** This is an anti-SSRF guard, not a TLS problem — the operator initially read it as an
HTTPS failure. Scoping to the exact `/24` is the least-privilege value that unblocks it, matching
the project's posture everywhere else. Captured because the appliance's bundled Forgejo (if any)
will need the same.

#### D4 — Build subsystem: `kixctl-build`, no general shell

**Decision.** The build runs in a dedicated, audited program (`scripts/kixctl-build`) that the
job invokes via an **argument array** (`Process::run([...])`), never a shell string. It takes
validated args (`--flake git+…?rev=<sha>`, `--attr`, `--kind`), runs `nix build` for the two
outputs (`config.system.build.metadata` + `.tarball`), and prints one line of JSON with the two
tarball paths. Input is validated (git ref must be commit-pinned; attr charset-restricted; kind
whitelisted). `--kind` is parameterized (`container` now; `vm` is the seam for the appliance).

**Why.** Verified: Symfony Process (which Laravel's `Process` wraps) with an array of arguments
execs the binary directly and escapes each arg — it exists precisely to replace
`exec`/`shell_exec`. So "not a general shell" is guaranteed twice: kixctl can only run that one
program, only with typed args. The program **is** the "build-host subsystem, distinct from the
control plane" named in Locked Decisions. Pinning the flake to `?rev=<sha>` makes Nix fetch the
repo hermetically at the exact commit — no local clone, no "whatever main is now" ambiguity.

#### D5 — Build/deploy identity invariant (NEW invariant)

**Decision.** The deploy pipeline runs as **one unprivileged service user**, owns its own
`~/.cache/nix` (later a dedicated `CacheDirectory=`), and **never invokes `nix` as root**. On the
appliance this becomes a real NixOS systemd service unit (`User=`, `CacheDirectory=`,
`StateDirectory=`) created by first-boot, so the ownership is correct by construction.

**Why.** A `Permission denied` on `~/.cache/nix/gitv3/*.lock` bit us — root-owned cache files
from a prior `sudo nix` run blocked the `kixadmin` build. `nix build` never needs root (the
nix-daemon does privileged store writes); root-owned cache can't appear if root never builds.
This is the exact failure end users hit at scale, and the operator was emphatic: it must be fixed
by construction, not vigilance. Sits next to the "kixctl cannot self-escalate" invariant. Maps to
roadmap P7-1 (Horizon/Reverb as NixOS user services) and P4-5 (dedicated builder instance).

#### D6 — Immutable per-revision instances; the job only ADDS

**Decision.** Each push launches a **new** immutable instance named `<repo leaf>-<sha7>` (e.g.
`demo-app-0b56f10`), sanitized to a valid Incus name. The deploy job only ever creates a
revision — it never stops, deletes, or mutates a running one. Cutover, reaping, and revert are
their own later slices.

**Why.** This is the product thesis literally in the naming: you don't mutate a deployment, you
stand up a new immutable one. It's also _less_ code for the first cut (no stop/delete/collision
dance) and _more_ correct (no downtime window). A revert target is a whole intact container, not
a hoped-for reverse migration. Bonus proven live: `volatile.base_image` + the sha7 name mean
"what revision is running / what's the rollback target" is already legible from Incus itself — the
future cutover/revert machinery reads existing data, no tracking layer to bolt on.

#### D7 — Idempotent aliased image import

**Decision.** `importImage` gained an optional `alias` and is idempotent: if the alias already
resolves, it returns the existing fingerprint; otherwise it imports and tags the image with the
per-revision alias. Launch is likewise tolerant (existing instance = already deployed).

**Why.** Verified: an image fingerprint is the SHA-256 of the concatenated metadata+rootfs, so
identical content re-imports to the same fingerprint and Incus rejects the duplicate. Aliasing by
revision name gives a stable, human-readable handle that mirrors the instance name and makes
re-running a deploy of the same commit a clean no-op. Importing then launching on the **same**
member (powerhouse) also sidesteps Incus's cross-member image-transfer path entirely.

#### D8 — Target selection: config now, UI picker later

**Decision.** The deploy target is a config value (`deploy.launch.target`, default `powerhouse`)
and the cluster defaults to the first active one. A per-deploy target picker in the UI is a later
slice.

**Why.** `--target` is mandatory on a cluster; the operator chose "powerhouse, keep it easy" for
the first cut. The three member names (`powerhouse`, `truck`, `miniserver`) are what a future
picker offers. A wrong-cluster pick fails cleanly (no member named `powerhouse` on the fixture),
so this is safe, not silent.

#### D9 — Appliance is the HEADLINE distribution (reframe)

**Decision.** The self-hosted appliance (boot an image → kixctl installs itself → first-run
wizard → "a better Proxmox," Incus invisible underneath) is re-ranked from "future try-it
distribution" (old P7-4 framing) to **the primary go-to-market front door**. It is the `--kind
vm` path of the _same_ build machinery this session proved on `--kind container`. First-boot is a
**bounded, one-time host-touching subsystem** (init Incus, mint the restricted cert, create admin,
set domain); steady-state kixctl stays disciplined and can't self-escalate.

**Why.** Proxmox's whole distribution model is "download ISO, boot, you have a hypervisor with a
UI" — substrate invisibility is the point, and it's how most Proxmox refugees will arrive. The
container spine was proven first because it's the smaller, safer case that de-risks the ISO. The
repo is literally named _immutable-deploy_; NixOS atomic upgrade + rollback is exactly what an
appliance needs. Two distinct things not to conflate: (a) the **appliance boots as a VM or bare
metal** — don't let it become VM-only; (b) the **builder emitting VM images as deploy targets** is
a separate later extension. Both ride the same `nix build` engine.

#### D10 — State boundary: the immutable unit holds no durable state

**Decision.** A deployed immutable container holds **no durable state**. State lives outside it
(a database it connects to). Only **config** — the thin layer telling the app where its state
lives, plus secrets/env — is declared once per app and injected into every revision. A fresh
revision comes up already knowing where its state is, so an update feels continuous.

**Why.** Keeping a database _inside_ the disposable unit would force SQL dump/restore on every
update (downtime scaling with data size; a failed restore is data loss on the happy path) — every
immutable system that tried it abandoned it. The tell: if it must be dumped, it's in the wrong
place. Once state is external, revisions swap freely and there's nothing to dump. kixctl's own
control plane already works exactly this way (its state is Postgres on .46, outside every
container), which is why the appliance "picks up where you left off" after a self-update.

#### D11 — Secret/config delivery: systemd credentials, env-bridge default

**Decision.** Config is delivered into the immutable container via **systemd credentials**
(Incus's `systemd.credential.<NAME>` instance key → lands in the container's
`/run/credentials/@system/`, 0400 root-only). The app's service pulls them in with
`ImportCredential=*` (no build-time list of keys → add env vars without rebuilding) and a small
**env-bridge** wrapper exports each as an environment variable, so a stock app just reads
`process.env.DATABASE_URL` (the default). A **strict file mode** (app reads
`$CREDENTIALS_DIRECTORY` directly, secret never in env) is the opt-in for max-security apps.

**Why.** The operator chose systemd because it's the init system and already does this — correct,
and the Incus `systemd.credential.*` key made delivery _cleaner_ than a file-push (one config key,
no new endpoint, folds into the config map `launchBuiltImage` already builds). The catch, surfaced
before committing: systemd credentials arrive as **files**, not env vars (that's the whole point —
credential data is not propagated down the process tree like env is). So the env-bridge is a
deliberate, contained final hop that keeps "push any app" true. **Ruled out:** Incus
`environment.*` keys — they reach PID1/`incus exec` but **not** a systemd service, so they'd
silently not reach the app. Empirically confirmed live: credential name casing is preserved
(`DATABASE_URL`, not lowercased), and `DynamicUser` + `ImportCredential` interoperate.

#### D12 — Secret chain: sops-nix + encrypt-at-rest + credential delivery

**Decision.** Three layers, each with the right tool for whether the secret exists at build time
or run time:

- **kixctl's own infra secrets** (Forgejo webhook secret, license signing key, Incus certs,
  Postgres password, `APP_KEY`) → **sops-nix**, decrypted at NixOS activation. They exist at
  config-authoring time; this was already the plan.
- **Deployed-app secrets** (a user's `DATABASE_URL`) → **encrypted at rest in kixctl's Postgres**
  via Laravel's `encrypted` cast (same as the cluster certs) under `APP_KEY` — and `APP_KEY` is
  itself a sops-nix secret, so app secrets are encrypted under a sops-guarded key.
- **Delivery into the container** → systemd credentials (D11). sops-nix structurally _cannot_
  reach this layer: it decrypts committed ciphertext into a specific machine's config at build
  time, but a user's runtime-typed `DATABASE_URL` doesn't exist at image-build time and must stay
  generic across every deployment.

**Why.** The operator's instinct ("why can't sops encrypt anything that might show a secret?") is
right for the operator-secret case and structurally impossible for the app-secret case — and
seeing exactly why is the useful part. The runtime-native equivalent of sops here is
`systemd-creds` encryption (`systemd.credential-binary.*`), which is the multi-tenant/enterprise
hardening, not the first cut.

#### D13 — Three-tier state architecture (build order)

**Decision.** How a deployed app gets a database, cheapest→richest, built in this order:

1. **Bring-your-own DB** (build first): app connects to a DB the user already has via an injected
   `DATABASE_URL`. kixctl stores/runs nothing extra, zero liability, fully self-hosted. This is
   what the state-boundary slice enables.
2. **kixctl provisions a DB container** (the Proxmox-killer): a persistent DB container on the
   user's own cluster — the one place a storage volume is correct, deliberately not immutable —
   with its connection string injected into the app. Reuses the exact injection path; the only new
   part is standing up the DB container.
3. **Managed/hosted DB** (paid, later): kixctl runs Postgres-as-a-service. Real revenue, but it
   contradicts the self-hosted thesis if it's the _only_ path (data leaves the user's cluster) and
   is "a company, not a feature" (backups, HA, SLAs, liability). It is option 2 pointed at a
   kixctl-operated target — opt-in convenience, never the default — and fits the monetization axis
   (cap scale/convenience, never capability).

**Why.** The operator's ultimate goal is a company: homelabbers use their own DB (free, reach);
on-prem businesses use provisioned DBs (self-hosted); some businesses want managed (upsell,
future). Same machinery underneath. Build 1 first (nearly free, unblocks everything), then 2,
hold 3 until a buyer asks.

#### D14 — Caddy delivery reality (for slice D, verified early)

**Decision.** Slice D will use a **static wildcard vhost + dynamic upstream** (deploys change
_data_, not Caddy config), NOT admin-API route injection.

**Why.** Verified against Caddy docs: under a NixOS-managed (Caddyfile) Caddy, config pushed to
the admin API is silently overwritten on the next reload — so any route kixctl POSTed to `:2019`
would die on the operator's next `rr` (`nixos-rebuild switch`). Slice D is also the surface the
eventual **cutover** acts on (swing route old→new), so D and the update-flow are the same rail.

#### D15 — Config carry-forward semantics

**Decision.** Injected config is applied at instance **create**, so changing a value takes effect
on the **next revision**, not a running one.

**Why.** This is exactly right for an immutable model (change config → new revision), and worth
remembering when building the update/revert flow: a config change is itself a deploy trigger.

---

### Deferred / captured (not blocking; each its own future slice)

- **Credstore hardening** — currently the injected value is visible in `incus config show`
  (plaintext instance config). In single-operator reality that's no new exposure (kixctl/the
  operator already hold it), but it matters for multi-tenant, config backups, and screenshots.
  The fix keeps the systemd-credential choice: push the value as a **file into the container's
  credstore** (`/run/credstore/…`) via the Incus files API instead of setting it as instance
  config — `ImportCredential` already searches the credstore, so the app side is untouched. Cost:
  the launch becomes create → push → start (a small `launchBuiltImage` refactor); verify the
  credstore path + push-before-start timing before building. Slots naturally with the
  multi-tenant/enterprise isolation tier (`systemd.credential-binary.*` + `systemd-creds`
  encryption for at-rest + TPM sealing).
- **Slice D — Caddy route** (see D14): static wildcard vhost + dynamic upstream.
- **Update/cutover/reap/revert lifecycle** — the operator's described flow: build-alongside →
  "update ready" surface → click Update = route cutover → old revision marked for removal in 7
  days (revert = swing back) → reap after 7 days. Each its own slice on top of the immutable
  identity established this session.
- **Tier-2 DB provisioning** (D13.2) when wanted.
- **`--kind vm`** build path (feeds the appliance, D9) and the builder emitting VM deploy targets.
- **Resolved this session (no longer open):** credential-name casing (preserved uppercase);
  `DynamicUser`+`ImportCredential` interaction (works); `git+https` fetch over SSH-only push (Nix
  fetches HTTPS anonymously from a public repo, independent of the operator's SSH push).

---

### Documentation status — the gap, named, with a plan

The operator flagged a real and correct worry: **a great deal has been decided and built, and
almost none of the _why_ is written down** — the risk being that the reasoning is lost before it's
documented, and that potential users can't see what differentiates kixctl from Proxmox or what
makes a future HIPAA/SOC2 story real.

What's already durable: every slice landed as a **descriptive commit**, and `prompt.md` is a real
resume doc, so the _what_ and _sequence_ survive in git regardless. What was _not_ captured — until
this file — is the _why_. This ledger is that capture.

The differentiation is genuine and worth writing for users: the security posture isn't bolted on,
it **falls out of the architecture** — immutable units, encrypted-at-rest under a sops-guarded key,
systemd-credential delivery, a control plane that cannot self-escalate, per-revision auditability
legible from Incus itself. That is exactly the substrate a compliance story stands on, and Proxmox
has no deployment model, no immutability, and no secret architecture to point at.

**Recommendation:** make a proper docs pass the next task (over resuming code), while the spine is
at a natural high-water mark. Concretely, produce as repo files:

- `README.md` — what kixctl is and why it's different (immutable deploy, self-hosted, Proxmox
  contrast).
- `LICENSE` — lock the **AGPL + commercial dual-license** decision (already the intent in
  `monetization.md`; make it a file, not an implication).
- `docs/architecture.md` — the immutable model, the state boundary, the secret chain, the
  build-identity invariant, the three-tier DB direction, the compliance posture.
- keep this `decisions.md` current each session.
- (Optional, later) a static docs site — mdBook or Astro Starlight fit the stack; content first.

Everything structured so the READMEs + git history are a substrate an AI session can regenerate
full documentation from — the durability property the operator asked for.

---

### Roadmap position after this session

P3-2's core is done (A/B/C + state boundary). Remaining in P3-2/adjacent, in order: **docs pass
(recommended next)** → credstore hardening → slice D (Caddy) → update/cutover/reap/revert lifecycle
→ tier-2 DB provisioning. Then the appliance line (P7 / the reframed headline distribution) picks
up the `--kind vm` seam.

---

## Session of 2026-07-27 — documentation pass (README + LICENSE + architecture)

Closed the doc-debt named at the end of the last session: a great deal was built and almost none
of the _why_ was shippable. This session produced the repo files that turn the reasoning already in
this ledger into public-facing documentation, and locked the license as a file rather than an
implication. No code behavior changed.

### What shipped

- **`README.md`** — replaced the stock Laravel skeleton README (which was still present, badges and
  all) with kixctl's identity: the intersection thesis (fabric + immutable deploy in one
  non-self-escalating control plane), the honest "not beating Proxmox at being Proxmox" framing, the
  deploy-flow sketch, the security-falls-out-of-architecture posture, a documentation map, and the
  dual-license note. Written as a substrate a future session can regenerate docs from.
- **`LICENSE`** — the **AGPL-3.0-or-later** verbatim text (fetched from the FSF/GitHub canonical
  source, not reconstructed from memory — verified: §13 "Remote Network Interaction" present,
  "END OF TERMS AND CONDITIONS" present, 661-line body), preceded by a clearly separated
  dual-license preamble that does not modify the AGPL document. `SPDX-License-Identifier:
  AGPL-3.0-or-later OR LicenseRef-kixctl-commercial`.
- **`LICENSING.md`** — the reader-facing explanation of the dual license: what AGPL §13 obligates,
  when a commercial license is actually needed, why AGPL (not BSL/SSPL — "verifiable because open"),
  the scale-not-capability paid line, and the no-mandatory-phone-home stance. Grounded in
  `monetization.md`.
- **`docs/architecture.md`** — the durable technical picture: three-layer rule, the immutable model
  + deploy flow, the restricted-cert invariant, the build-identity invariant, the state boundary +
  secret/config delivery, the secret chain, the three-tier DB direction, the layered auth model, the
  NixOS image base, the appliance direction, and the compliance posture. Cross-references D1–D15 and
  cites real file paths so it stays regenerable.
- **`composer.json` / `package.json` hygiene** — corrected the leftover skeleton metadata:
  `name` `laravel/laravel` → `kixago/kixctl`, `license` `MIT` → `AGPL-3.0-or-later`, real
  description/keywords; added the SPDX `license` field to the (private) `package.json`. This closes
  the same doc-debt at the metadata layer — the repo no longer *declares* MIT while the LICENSE says
  AGPL.

### Decisions, with reasoning

#### D16 — LICENSE is one file, AGPL text verbatim, dual-license note above it

**Decision.** Ship a single `LICENSE` file containing the unmodified AGPL-3.0-or-later text with a
short dual-license preamble *above* it (separated by a rule), plus a separate human-readable
`LICENSING.md`. Copyright line: `Copyright (C) 2026 Kixago`.

**Why.** The FSF permits verbatim copying of the license document but not altering it, so the
dual-license notice sits outside the license text, never inside it. Making the AGPL a real file (not
"AGPL in spirit") is what the last session flagged as missing. `LICENSING.md` carries the nuance a
bare license file can't. **One thing to confirm with counsel** (per `monetization.md`'s standing
caveat): the exact legal-entity name on the copyright line and the CLA/DCO choice that keeps the
commercial-relicensing option available on outside contributions — placeholder `Kixago` and
`licensing@kixago.com` used until confirmed.

#### D17 — Metadata must not contradict the license

**Decision.** Fix `composer.json`/`package.json` in the same pass as the LICENSE.

**Why.** A `"license": "MIT"` in `composer.json` alongside an AGPL `LICENSE` file is a real
contradiction a packager or a lawyer would flag, and it's the kind of leftover-skeleton detail that
undercuts the "verifiable because open" posture. Cheap to fix, and it belongs with the license work.

### Follow-ups captured (not blocking)

- **Confirm the legal-entity name** on the LICENSE copyright line and set up
  `licensing@kixago.com` (or the real alias) before public release.
- **`CONTRIBUTING.md` + CLA/DCO** — referenced by `LICENSING.md`; write when contributions become
  real, so the commercial-license option stays available on contributed code.
- **Wire the README's status line forward** as slices land (credstore hardening → Caddy → cutover),
  so "pre-release, spine proven" stays accurate.
- The optional **static docs site** (mdBook / Astro Starlight) is now unblocked — content exists to
  seed it — but remains deferred; content first.

### Roadmap position after this session

Docs pass done (the recommended next task). Remaining in P3-2/adjacent, unchanged in order:
**credstore hardening → slice D (Caddy, static wildcard + dynamic upstream) → update/cutover/reap/
revert lifecycle → tier-2 DB provisioning.** Then the appliance line picks up the `--kind vm` seam.
