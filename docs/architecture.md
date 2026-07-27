# kixctl architecture

_The durable technical picture of kixctl: how a deploy actually flows, where state and secrets
live, and the invariants the whole trust story rests on. Companion to
[`../decisions.md`](../decisions.md) (the per-decision reasoning, referenced here as D1–D15) and
[`security/`](security/) (the Incus client-cert scope and ingress posture). This file is written to
be stable: it describes the model, not the week's to-do list._

---

## 1. The three-layer rule

kixctl is a Laravel + Livewire + Filament control plane whose **own state is Postgres** — never a
synced copy of Incus. There are exactly three layers, and the boundaries are not crossed:

1. **The control plane** (Laravel/Filament) holds authority and its own state.
2. **The Incus REST API** is the _only_ backend. Laravel never touches the host and never the raw
   Incus socket — every cluster action is an HTTPS call to Incus.
3. **Incus and the host** do the isolation and the privileged work.

The single, deliberate exception is `nix build`, which must touch the build host. It is isolated in
its own audited subsystem (§4), distinct from the control plane, so "Laravel talks only to the
Incus REST API" holds with no carve-outs in the control plane itself.

Two `Cluster` types exist and must not be confused: `App\Models\Cluster` (Eloquent — encrypted
cert/key at rest, `toEndpoint()`) versus `App\Services\Incus\Cluster` (a runtime value object).
`App\Services\Incus\IncusClient` operates on the _value object_ only.

## 2. The immutable deploy model (D6)

kixctl does not mutate a running deployment. Each push launches a **new** immutable instance named
`<repo leaf>-<sha7>` (for example `demo-app-0b56f10`), sanitized to a valid Incus name. The deploy
job **only ever adds a revision** — it never stops, deletes, or mutates a running one. Cutover,
reaping, and revert are separate later slices that act on this identity.

This is the product thesis expressed literally in the naming, and it is also _less_ code and _more_
correct for the first cut: no stop/delete/collision dance, no downtime window, and a revert target
that is a whole intact container rather than a hoped-for reverse migration. Crucially, the
running-revision identity is **legible from Incus itself**: `volatile.base_image` plus the `-<sha7>`
instance name already answer "what revision is running?" and "what is the rollback target?" with no
tracking layer to bolt on. The future cutover/revert machinery reads existing data.

### The deploy flow

The spine is built as ordered slices (D1), Caddy deliberately last because it is the thorniest
surface and touches a second host:

```
git push
  │
  ▼  POST /api/deploy/forgejo              routes/api.php
     ForgejoWebhookController              verify HMAC → parse push → queue
  │   └─ App\Services\Deploy\WebhookSignature::valid()   (pure, hash_equals)
  ▼  App\Jobs\DeployFromPush   (queue: incus; tries=1; timeout=1800)
  │
  ├─ build    scripts/kixctl-build  --flake git+<clone>?rev=<sha> --attr <a> --kind container
  │           └─ nix build …system.build.metadata + .tarball → {"metadata":…,"rootfs":…}
  │
  ├─ resolve  cluster (config deploy.launch.cluster, default first-active)
  │           target  (config deploy.launch.target, default powerhouse)     (D8)
  │
  ├─ config   DeployAppConfig rows for this app → systemd.credential.<KEY> map   (§5)
  │
  ├─ import   IncusClient::importImage(cluster, meta, rootfs, alias: <repo>-<sha7>)   (D7, idempotent)
  │
  └─ launch   IncusClient::launchBuiltImage(cluster, <repo>-<sha7>, fingerprint,
              target, config: credentials)     — create + start, immutable
  │
  ▼  [slice D, next]  Caddy route → the running revision       (D14)
```

`DeployFromPush` is `tries = 1` on purpose: a deploy is never safe to auto-retry blindly. Every
failure path (`build_failed`, `build_bad_output`, `no_cluster`, `import_failed`, `launch_failed`)
logs and returns rather than throwing into a retry.

### Webhook authentication (D2)

The trigger endpoint lives on the stateless `api` group — no session, no CSRF; the _signature_ is
the auth, not a logged-in user. Forgejo signs the **raw** request body with HMAC-SHA256 and sends
the hex digest in `X-Forgejo-Signature`. `WebhookSignature::valid()` verifies it with `hash_equals`
(constant-time, to avoid a timing side-channel). Reading the raw body is mandatory: re-encoding the
parsed array would change bytes and break the HMAC. The shared secret is **operator-chosen** —
identical in the Forgejo webhook form and `FORGEJO_WEBHOOK_SECRET` (`config/deploy.php`); Forgejo
does not generate it. Only pushes to the repository's default branch are deployed.

> Operational note (D3): Forgejo's `[webhook] ALLOWED_HOST_LIST` blocks private IPs by default. The
> fix is to scope it to the cluster's subnet (e.g. `"external,192.168.2.0/24"`), which is an
> anti-SSRF guard — not a TLS problem, though it first reads like one.

## 3. Transport: the restricted certificate (the core invariant)

kixctl authenticates to Incus with a **restricted TLS client certificate** (`restricted: true`,
`projects: [default]`). This is the load-bearing security property:

- **kixctl cannot raise its own level of access.** No self-escalation is ever built, suggested, or
  worked around. If the web tier is compromised, the attacker inherits exactly this cert's scope and
  nothing more.
- **Scope remediation is always the target cluster administrator's action**, never kixctl's.
- **The whole deploy pipeline rides this same cert.** Confirmed live: image import
  (`POST /1.0/images`) _and_ launch both succeed over the ordinary restricted cert — no host-local
  socket carve-out is needed for the builder.

Full reasoning and the exact grant are in [`security/incus-scope.md`](security/incus-scope.md); the
control plane is a web-facing target holding fleet authority (the "Coolify risk shape"), so the
posture is _containment, not invulnerability_, and a scoped identity is what makes containment real.

## 4. The build-identity invariant (D4, D5)

The build is the one host-touching step, and it is fenced two ways.

**Not a general shell (D4).** The job invokes `scripts/kixctl-build` as an **argument array** via
Laravel's `Process::run([...])`. Symfony Process with an array execs the binary directly and escapes
each argument — it exists precisely to replace `shell_exec`. So kixctl can run _only that one
program, only with typed arguments_. The program itself validates every input: the git flakeref
must be commit-pinned (`?rev=<sha>`), the attribute is charset-restricted, and `--kind` is
whitelisted (`container` now; `vm` is the seam for the appliance, deliberately parameterized, not
hardcoded). Pinning to `?rev=<sha>` makes Nix fetch the repo hermetically at the exact commit — no
local clone, no "whatever `main` is right now" ambiguity. Nix fetches over `git+https` anonymously
from the public repo, independent of the operator's SSH-only push.

**Never as root (D5).** The deploy pipeline runs as **one unprivileged service user**, owns its own
`~/.cache/nix`, and **never invokes `nix` as root** — the nix-daemon does the privileged store
writes; `nix build` never needs root. Root-owned cache files from a prior `sudo nix` run once
blocked an unprivileged build; the rule is that this must be impossible _by construction_, not by
vigilance. On the appliance this becomes a real systemd unit (`User=`, `CacheDirectory=`,
`StateDirectory=`) created at first boot, so ownership is correct from the start. This invariant
sits next to "kixctl cannot self-escalate."

## 5. The state boundary (D10) and secret/config delivery (D11)

**The immutable unit holds no durable state.** State lives outside it — a database it connects to.
Keeping a database _inside_ a disposable unit would force a SQL dump/restore on every update
(downtime scaling with data size; a failed restore is data loss on the happy path) — every
immutable system that tried it abandoned it. The tell: _if it must be dumped, it is in the wrong
place._ Once state is external, revisions swap freely and there is nothing to dump. kixctl's own
control plane already works this way (its Postgres is external to every container), which is why the
appliance "picks up where you left off" after a self-update.

**Only config is declared once and carried forward.** The thin layer that tells an app where its
state lives — plus secrets and env — is stored per app in `deploy_app_config`
(`App\Models\DeployAppConfig`), keyed by app + key, the value `encrypted` at rest (same Laravel
`encrypted` cast as the cluster certs). At launch, `DeployFromPush` loads the app's rows and injects
each as a file pushed into the container credstore (`/etc/credstore/<KEY>`, 0400 root-only), between instance create and start. Because config is applied at instance
**create**, changing a value takes effect on the **next revision**, not a running one (D15) — a
config change is itself a deploy trigger, which is exactly right for an immutable model.

**Delivery is via systemd credentials, not env or image (D11).** The injected values land in the
container's `/run/credentials/@system/` (0400, root-only) — never baked into the image, never in the
process tree by default. The app's NixOS service pulls them in with `ImportCredential=*` (a wildcard,
so env vars can be added without rebuilding the image) and a small **env-bridge** wrapper exports
each as an environment variable, so a stock app just reads `process.env.DATABASE_URL` (the default).
A **strict file mode** (the app reads `$CREDENTIALS_DIRECTORY` directly, secret never in env) is the
opt-in for maximum-security apps. Incus `environment.*` keys were ruled out: they reach PID 1 and
`incus exec` but **not** a systemd service, so they would silently not reach the app. The job logs
injected **key names only, never values**.

> Proven live: a `DATABASE_URL` typed into kixctl's store surfaced as `process.env.DATABASE_URL`
> inside a brand-new container, carried forward unchanged across three successive immutable
> revisions. Credential-name casing is preserved (`DATABASE_URL`, not lowercased), and
> `DynamicUser` + `ImportCredential` interoperate.

### Credstore delivery (hardened)

The injected value is delivered as a **file pushed into the container credstore**
(`/etc/credstore/<KEY>`, 0400 root-only) via the Incus files API, between instance **create** and
**start** — so it never appears in `incus config show`. `ImportCredential=*` enumerates
`/etc/credstore/` as a system-credential source (systemd.exec(5)), so the app side is untouched. The
path is `/etc/credstore`, **not** `/run/credstore`: `/run` is a boot-time tmpfs and a file pushed
there before start would be wiped, whereas `/etc` persists — verified live on the deploy base
(pushed while stopped, present after boot, delivered into a service via
`systemd-run -p ImportCredential=*`). The file is plaintext on the container's rootfs disk (0400);
at-rest encryption (`systemd.credential-binary.*` + `systemd-creds`, TPM sealing) is the deferred
multi-tenant/enterprise tier.

## 6. The secret chain (D12)

Three layers, each with the right tool for whether a secret exists at build time or run time:

- **kixctl's own infrastructure secrets** (Forgejo webhook secret, license signing key, Incus certs,
  Postgres password, `APP_KEY`) → **sops-nix**, decrypted at NixOS activation. They exist at
  config-authoring time.
- **Deployed-app secrets** (a user's `DATABASE_URL`) → **encrypted at rest in kixctl's Postgres**
  under `APP_KEY` — and `APP_KEY` is _itself_ a sops-nix secret, so app secrets are encrypted under a
  sops-guarded key.
- **Delivery into the container** → systemd credentials (§5).

sops-nix structurally _cannot_ reach the delivery layer: it decrypts committed ciphertext into a
specific machine's config at build time, but a user's runtime-typed `DATABASE_URL` does not exist at
image-build time and must stay generic across every deployment. The runtime-native equivalent of
sops here is `systemd-creds` encryption — the multi-tenant/enterprise hardening, not the first cut.

## 7. The three-tier database direction (D13)

How a deployed app gets a database, cheapest → richest, built in this order:

1. **Bring-your-own DB** (first, and what the state-boundary slice enables): the app connects to a
   database the user already has via an injected `DATABASE_URL`. kixctl stores and runs nothing
   extra — zero liability, fully self-hosted.
2. **kixctl provisions a DB container** (the Proxmox-killer): a persistent database container on the
   user's _own_ cluster — the one place a storage volume is correct and the unit is deliberately
   _not_ immutable — with its connection string injected into the app. Reuses the exact injection
   path; the only new part is standing up the DB container.
3. **Managed / hosted DB** (paid, later): kixctl runs Postgres-as-a-service. Real revenue, but it is
   option 2 pointed at a kixctl-operated target — opt-in convenience, never the default, never the
   only path (data leaving the user's cluster contradicts the self-hosted thesis if forced). It fits
   the monetization axis: cap scale and convenience, never capability.

Same injection machinery underneath all three. Build 1 first (nearly free, unblocks everything),
then 2, and hold 3 until a buyer asks.

## 8. The layered authorization model

Authorization is three independent layers — do not collapse them:

1. **The Incus cert** — _what the kixctl service may ask Incus to do_ (restricted, project-scoped;
   §3). This is the outer bound; nothing kixctl does can exceed it.
2. **kixctl's own RBAC** — _which logged-in user may do what in kixctl._ Filament Shield plus
   per-verb refusals at the Livewire-method level (`->visible()` is presentation on top; the method
   refusal is the real fence). `super_admin` bypasses the gate via Shield's `Gate::before`. Roles
   are seeded per verb (e.g. `operator` gets create/update but not resource-destroying deletes;
   `profile.update` is admin-only as the widest blast radius).
3. **Incus-native fine-grained authorization** (OpenFGA) — _optional, enterprise-only_, not part of
   the base product.

Related invariants that shape the UI: degradation is **per-capability, not per-cluster** (a denied
read dims one tab with a "why?" notice, never the whole cluster), scope is verified at onboarding,
and any loop over multiple clusters isolates each in try/catch so one unreachable cluster never
breaks a page.

## 9. The NixOS image base

Every built image imports a locked base module (`kixctl-base.nix`) that carries the defaults a
stock `images:nixos` gives for free but a custom image must supply — each discovered against a real
cluster, not assumed:

- **systemd-networkd is the sole network manager** (`useNetworkd = true` +
  `useDHCP = lib.mkForce false`), or networkd and dhcpcd fight over `eth0` and networking is lost.
- **DNS comes from the DHCP lease via `resolved`** — no hardcoded nameserver, so an image works on
  any network (`useHostResolvConf = lib.mkForce false` + `services.resolved.enable = true`).
- **`git` is present globally** in every container (updates/pulls need it).
- Firewall on; each app module opens only its own port.
- A Node service's `ExecStart` runs a **built file, not inline `node -e`** (systemd tokenizes
  ExecStart with no shell; nested quotes get mangled).

Two truths are set at **launch**, not in the image: `security.nesting=true` is mandatory for a
NixOS container, and `--target <member>` is mandatory on a cluster (the deploy path always sends
both). Apps are built with `pkgs.buildNpmPackage` + `pkgs.importNpmLock`, which reads the integrity
hashes already in the lockfile and needs **no `npmDepsHash`** — this is what lets the builder
compile whatever a user pushes with no human in the loop computing a hash.

## 10. The appliance direction (D9)

The self-hosted **appliance is the headline distribution**: boot an image → kixctl installs itself →
a first-run wizard sets it up → "a better Proxmox," with Incus invisible underneath. It is the
`--kind vm` path of the _same_ build machinery already proven on `--kind container`. First-boot is a
**bounded, one-time host-touching subsystem** (init Incus, mint the restricted cert, create the
admin, set the domain); steady-state kixctl stays disciplined and cannot self-escalate. Two things
not to conflate: the appliance **boots as a VM or bare metal** (it must not become VM-only), and the
builder **emitting VM images as deploy targets** is a separate later extension. Both ride the same
`nix build` engine. The repository is named _immutable-deploy_ for a reason: NixOS atomic upgrade +
rollback is exactly what an appliance needs.

## 11. Compliance posture

kixctl's security properties are not a feature bolted on for a checklist — they **fall out of the
architecture**, which is what makes a HIPAA or SOC 2 story credible rather than aspirational:

- **Immutable units** with per-revision identity legible from Incus itself → a real,
  reconstructable change history (_what ran, when, from which commit_).
- **Secrets encrypted at rest** under a sops-guarded key, delivered as root-only systemd
  credentials, **never** baked into an image and logged only by key name.
- **A control plane that cannot self-escalate** — a bounded, auditable blast radius if the web tier
  is ever compromised.
- **A build pipeline that never runs as root** and can only execute one validated program.
- **Layered authorization** with per-verb enforcement and an optional enterprise OIDC + fine-grained
  path.

Proxmox, by contrast, has no deployment model, no immutability, and no secret architecture to point
at. That gap is the substrate kixctl's compliance and enterprise story is built on — see
[`../differentiation.md`](../differentiation.md) for the market framing and
[`../monetization.md`](../monetization.md) for where enterprise governance sits on the paid axis.

---

_This document is regenerable: its claims trace to the code (`app/Jobs/DeployFromPush.php`,
`app/Services/Deploy/`, `app/Services/Incus/IncusClient.php`, `scripts/kixctl-build`,
`config/deploy.php`, `config/license.php`) and to the decision ledger (`../decisions.md`, D1–D15).
When the architecture changes, update this file and the ledger together._
