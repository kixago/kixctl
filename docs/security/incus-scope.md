# Incus Access Scope — Control-Plane Hardening (P2-4)

**Status:** locked — core gate closed
**Applies to:** how kixctl authenticates to and talks to Incus, in dev and in production
**Last reviewed:** 2026-07-20
**Verified against:** Incus authorization and authentication docs (main, last upstream update July 2026), Incus 7.2 line

This is a decision record, not a runbook. It captures what kixctl is allowed to ask Incus to do, why the grant is drawn exactly where it is, and the reasoning I want on paper so nobody — including me, six months from now — quietly widens it back out to blanket admin because it was convenient on a Friday.

---

## 1. What we are actually defending

The control plane is the soft target. Every instance kixctl launches is isolated from every other instance and from the host — that is Incus doing its job, and it is genuinely good containment. But the control plane sits outside all of that isolation on purpose: it is web-facing, and it holds the authority to command the whole fleet. Its job is to reach everything, so nothing protects it by isolating it. This is the Coolify risk shape, and it is the one place the security story has to be _lived_ rather than claimed.

The reference case is the January 2026 Coolify disclosure: eleven critical vulnerabilities, several rated CVSS 10.0, roughly 53,000 publicly exposed instances, and a patch-immediately advisory from Belgium's national cybersecurity center. Most of those bugs needed an authenticated user and followed a "user input reaches a shell" pattern, against a daemon holding a root-equivalent Docker socket. Per-instance isolation shrinks the blast radius of that class of bug. It does not make a control plane immune to a bug in its own code. The honest claim is containment, not invulnerability — and containment is worth nothing if the credential the control plane holds is itself unlimited.

So the whole of P2-4 comes down to one principle: **the identity kixctl uses to talk to Incus must be able to do exactly what kixctl does, and nothing else.** If the web tier is ever popped, the attacker inherits that identity — and a scoped identity is the difference between "they can manage instances in one project" and "they own the cluster, the host, and every other tenant on it."

---

## 2. The rule that makes this tractable

kixctl never touches the host. It decides and it remembers; the work happens through the Incus REST API as structured data. Laravel builds a request body, hands it to the API, and reads back JSON. There is no code path where a value typed by a user is interpolated into a shell command, because there is no shell — everything is an HTTP verb against a documented endpoint, and every path segment that carries a name is URL-encoded before it goes on the wire.

That single design choice is what lets the rest of this document be short. The entire attack surface between kixctl and Incus is a known, enumerable set of API calls. We can draw the permission boundary to fit that set precisely, because we know every call we make.

---

## 3. The full surface — everything kixctl asks of Incus

Enumerated from the actual client (`app/Services/Incus/IncusClient.php`), not from memory. Every `/1.0/*` path the application touches lives in that one file; nothing hits the API from a Livewire component, a job, or anywhere else. The access level column is the ceiling each call needs.

| Verb + path                                         | What it does                       | Access level                  |
| --------------------------------------------------- | ---------------------------------- | ----------------------------- |
| `GET /1.0/cluster/members?recursion=1`              | List nodes for the fleet view      | server read (authenticated)   |
| `GET /1.0/cluster/members/{name}/state`             | Node RAM / load / pool usage       | server read (authenticated)   |
| `GET /1.0/instances?recursion=2`                    | List instances + live state        | viewer, in-project            |
| `GET /1.0/instances/{name}?recursion=1`             | Instance detail, config, devices   | viewer, in-project            |
| `GET /1.0/instances/{name}/snapshots?recursion=1`   | List snapshots                     | viewer, in-project            |
| `GET /1.0/instances/{name}/logs` and `/logs/{file}` | List and read log files            | viewer, in-project            |
| `GET /1.0/instances/{name}/console`                 | Console ring buffer                | viewer, in-project            |
| `GET /1.0/profiles?recursion=1`                     | Profile names for the create form  | viewer, in-project            |
| `GET /1.0/operations/{uuid}` and `/wait`            | Poll / block on an async operation | operations viewer, in-project |
| `POST /1.0/instances[?target=]`                     | Create an instance                 | operator, in-project          |
| `POST /1.0/instances/{name}/snapshots`              | Create a snapshot                  | operator, in-project          |
| `PUT /1.0/instances/{name}/state`                   | Start / stop / restart             | operator, in-project          |
| `PUT /1.0/instances/{name}` (body `restore`)        | Restore a snapshot                 | operator, in-project          |
| `DELETE /1.0/instances/{name}/snapshots/{snap}`     | Delete a snapshot                  | operator, in-project          |
| `DELETE /1.0/instances/{name}`                      | Delete an instance                 | operator, in-project          |

The ceiling across the whole application is **operator, confined to a project**, plus a handful of authenticated-level reads for the fleet overview. There is no call anywhere that needs server administration.

---

## 4. The negative space — what kixctl never touches

Least privilege is defined as much by what is deliberately absent as by what is present. kixctl makes none of the following calls, and the grant must not include them:

- **No image management on the cluster.** The image catalog is read from the public simplestreams server; the actual pull happens as a side effect of the create operation. kixctl never manages images through the cluster API.
- **No network endpoints.** Network information is read off each instance's expanded devices, not from a networks endpoint. kixctl reads, it does not configure networks.
- **No storage-pool writes.** Pool usage is read from node state. Creating a pool is a host-level act kixctl has no business performing.
- **No project, certificate, or server-config writes.** kixctl does not create projects, mint certificates, or edit the daemon's own configuration.
- **No `/1.0/events` yet.** The live log tail over the events socket is not built; when it is, it is a read (`type=logging`/`lifecycle`), and this document gets revised to add it.

---

## 5. The mechanism — a restricted TLS client certificate

Incus offers three ways to authorize a network client: native TLS project restriction, OpenFGA, and scriptlet authorization. Only one of them fits a service that authenticates with a certificate and holds no external identity infrastructure, and it happens to be the simplest: a **restricted TLS client certificate scoped to a project.**

When a trusted TLS certificate is marked restricted, Incus prevents it from making global configuration changes and confines it to the project or projects it is granted — operator within those projects, and nothing at the server level. That maps exactly onto Section 3: everything kixctl does is either an operator action inside its project or an authenticated-level read. The certificate grants precisely that and denies everything else, with no OpenFGA server, no identity provider, and no moving parts beyond the certificate itself.

The two paths that were **not** chosen, and why:

- **The local admin socket is a dev-only shortcut and cannot ship.** On my desktop, which is itself cluster member `powerhouse`, dev talks to Incus over the local Unix socket. That socket is root-equivalent on the host — it is the blanket-admin credential this entire gate exists to keep out of production. It stays in dev, behind the `driver` switch, and never becomes the production path.
- **OpenFGA is deferred, not rejected.** Per-user, per-instance authorization enforced inside Incus is a real capability, but it requires end users to authenticate via OIDC (an external identity provider) and an OpenFGA server with its own database. That is the right shape for an enterprise tier, not a baseline dependency. See Section 10.

### The concrete grant

The current cluster runs a single project, `default`, holding all instances, all profiles, and the one `powerpool` btrfs pool across `powerhouse`, `truck`, and `miniserver`. The scope is therefore:

```yaml
name: kixctl
restricted: true
projects:
  - default
```

This is live as of 2026-07-20. The `kixctl` client certificate — an ECDSA P-384 key generated for the app alone, distinct from any admin identity — sits in the cluster's shared trust store (fingerprint `19bf4593…b34b66`). It was added restricted from the first second, via `incus config trust add-certificate <file> --restricted --projects default`, so there was never a window in which it existed as unrestricted admin. Before anything used it, it was proven against the live cluster with read-only calls only: `/1.0` returned `auth: trusted` for this certificate over `tls`, and it could list instances and cluster members in `default`.

### The powers this identity must never hold

Incus's own authorization model flags a specific set of relations as unsafe for any identity not trusted with root on the host. kixctl requires none of them, and they are listed here so the exclusion is explicit and permanent: server admin, server operator, server edit, create-storage-pools, create-projects, create-certificates, certificate edit, storage-pool edit, and project admin. If a future change appears to need any of these, that is the signal to stop and rethink the design, not to widen the grant.

---

## 6. Dev vs production, and how we got there without locking me out

The `driver` switch in `config/incus.php` carries both postures: `socket` for local dev on a cluster member, `https` for a real install over the scoped certificate. Production is always `https`, and as of 2026-07-20 dev is too — the flip is done.

The cutover was additive and reversible by design, and that is how it went: the certificate was generated and trust-added first, proven with read-only calls, and only then was `INCUS_DRIVER` flipped to `https`. Throughout, the local admin socket on `powerhouse` stayed as break-glass — a wrong grant could not lock me out, because the socket was still there until the certificate was proven. It is proven; `https` is the path.

One implementation detail is worth recording because it is exactly the kind of thing that bites in production: `config/incus.php` resolves the certificate and key paths to absolute form regardless of the process working directory. A relative path in `.env` works when you launch from the project root but fails the moment a Horizon worker or a systemd unit runs from somewhere else — the classic works-in-dev, fails-in-prod trap. The resolver closes it: absolute paths pass through, relative paths resolve against the app root, so the credential is found no matter who starts the process.

---

## 7. Shell safety — a reviewed guarantee

No user-controlled input reaches a shell, anywhere, because kixctl does not shell out. Instance names, snapshot names, log filenames — everything a user can influence — travels as either a structured field in a JSON body or a URL-encoded path segment against a fixed endpoint. There is no command interpolation to inject into. This is the specific bug class that made the Coolify disclosures dangerous, and the architecture forecloses it rather than filtering for it. Recorded here as a reviewed property of the system, to be re-checked whenever a new Incus call is added.

---

## 8. Network segmentation and transport

The admin surface is not casually internet-exposed. The intended posture is that the control plane and its API traffic sit behind WireGuard; remote clusters — mine and, later, customers' — are reached over that tunnel, not over a certificate flapping in the public breeze. The certificate is the identity; WireGuard is the perimeter; they are complementary, not redundant. This is the recorded design; standing up the tunnel and moving the admin surface onto it is its own deploy (see §12).

The `forceTLS` flip belongs with that same work. Once the admin surface is behind TLS, Reverb and the Filament panel's Echo config (`config/filament.php` → `broadcasting.echo`) move from the dev setting of `ws://localhost:8080` to `wss` with `forceTLS => true`. Dev keeps the plaintext local setting; production does not. It stays `false` deliberately until the admin surface is actually behind TLS — flipping it early only breaks local Reverb.

---

## 9. kixctl's own state database

kixctl's Postgres is its own state — users, roles, jobs, sessions, cache pointers — and it is deliberately separate from everything else. It never holds a copy of Incus state (the data model is live-first: fleet state is always read from Incus at render time). Nothing but the platform touches this database. In the appliance posture it runs under the floor as a managed service the owner can inspect but that otherwise stays invisible; a bolt-on install may point at an external database if the operator insists, though that is discouraged and unsupported as a default.

That state has a real backup path as of 2026-07-20. On the database host it is a declarative NixOS `services.postgresqlBackup` unit: `pg_dump` of the `kixctl` database, run by the `postgres` user over the local socket — peer auth, no password, no network — on a nightly timer made persistent, so a dump is not silently skipped if the box was down at the scheduled time. It writes `-C --no-owner` so a restore recreates the database and ports cleanly onto a fresh role set, gzip-compressed with tooling already present on the box. It is OS-owned and independent of the app: kixctl can be dead and the backup still runs, which is the entire point — a control plane that can rebuild the world but cannot recover its own memory is only half-hardened.

Two limits are named honestly as the next increment, not pretended away. The unit keeps only the latest dump — no multi-generation retention yet — and it writes to the database host's own disk, so it survives the app dying but not the host dying. Real retention (N generations) and an offsite copy are the follow-on that turns "a nightly dump exists" into "disaster-recoverable."

---

## 10. Two postures, one primitive, and where authorization actually lives

kixctl ships in two postures from a single codebase. As an **appliance**, it is the operating system: opinionated, owning the whole stack, Incus local, workloads launched on top — the Proxmox shape. As a **bolt-on**, it points at an Incus a company already runs, possibly on hardware and an OS that are none of my business. The rule is: opinionate what I own, integrate with what the user owns. Greenfield gets sane, opinionated defaults. An existing cluster gets read first and touched carefully — a tool that "helpfully corrected" a stranger's profile backing two dozen live instances would be a catastrophe, and I know exactly how that feels because my own `power` profile backs twenty-three of them.

The scoped certificate is the right primitive for both postures. Even as the appliance, blanket admin is not the model I want to ship: the whole differentiator is one dashboard reaching my cluster and my customers', and every hop deserves the same scoped, auditable identity. A single auth path across local and remote, with blast radius capped everywhere, is worth more than the minor convenience of a local socket.

Authorization is layered, and the layers are distinct:

- **The certificate** is the transport identity — what the kixctl _service_ may ask of Incus. That is this document.
- **kixctl's own RBAC** (Filament Shield, the per-verb Livewire-method gates) is what a logged-in _person_ may ask of kixctl. Gate the verb, not the view. This is the free and open-source tier's model, and it needs no identity provider and no OpenFGA.
- **Incus-native OpenFGA**, for a future enterprise tier, is optional and opt-in: end users authenticate via OIDC and Incus itself enforces per-instance access, with kixctl acting as the console that provisions those identities and writes the authorization tuples. Enforcement moves into the hypervisor layer — it holds even against the `incus` CLI, and even against a bug in kixctl. It is a tier feature, never a baseline requirement.

The design keeps a clean seam between those layers so the enterprise path stays open without the open-source path ever having to carry its weight. OpenFGA and its database are enterprise infrastructure that would run under the floor on the main system, visible to owners and otherwise silent — kept entirely separate from kixctl's own Postgres, which is a different thing serving a different purpose.

---

## 11. Reviewed observation — secrets in instance configuration

Worth recording because it is a real property of any Incus fleet: operators sometimes place credentials directly in instance configuration, and kixctl's instance-detail view reads expanded configuration, so anything sitting there will render in the UI. kixctl is a faithful mirror — it does not invent the exposure, but it does surface it. The guidance that follows from this is simply that sensitive material belongs outside instance config (attached volumes, a secrets mechanism), and that the UI should treat known-sensitive keys with appropriate discretion when that work is scheduled. No action is required for the gate; it is logged so the property is known rather than discovered.

---

## 12. Gate status

| Item                                                    | State                                                  |
| ------------------------------------------------------- | ------------------------------------------------------ |
| API surface enumerated from real code                   | done — §3                                              |
| Least-privilege scope designed (not blanket admin)      | done — §4, §5                                          |
| Restricted certificate generated and proven read-only   | done — §5 (fingerprint `19bf4593…b34b66`)              |
| Driver on `https` (socket kept only as dev break-glass) | done — §6                                              |
| Cert paths resolved absolutely (CWD-independent)        | done — §6                                              |
| Shell-safety guarantee reviewed and recorded            | done — §7                                              |
| Postgres backup path                                    | done — §9 (retention + offsite are the next increment) |
| Authorization-provider seam recorded                    | done — §10                                             |
| Network segmentation (WireGuard)                        | posture recorded — §8; implementation pending          |
| `forceTLS` → `true` at the TLS switch                   | deferred with the WireGuard work — §8                  |

**Done when** every Coolify-shape risk above is addressed and written down, and dev talks to Incus over a scoped certificate rather than the socket. Both hold: the risks are recorded here, and the scoped certificate is live with the driver on `https`. **The core gate is closed.**

What remains is follow-on hardening, not gate conditions: putting the admin surface behind WireGuard and flipping `forceTLS` with it, and adding retention plus an offsite copy to the database backup. Those are tracked as their own work, not blockers on P2-4.
