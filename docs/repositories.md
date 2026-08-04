# Repositories — git-host onboarding (P3-6)

kixctl deploys from git repositories you register. A registered repository is the
whole answer to *how* to fetch and build your app: its clone URL, branch, build
attribute, and an optional webhook secret. Two triggers land a deploy, and both
produce the identical build:

- **Webhook** — the low-latency path. The host calls kixctl the instant you push.
- **Poll** — the host-agnostic baseline. kixctl checks the repository on a
  schedule with `git ls-remote` and deploys a new commit even with no webhook.

Manage repositories under **Settings → Repositories**.

## SSH first

The clone URL carries the authentication story. An SSH URL —
`ssh://git@host:port/owner/repo.git` — authenticates through the access this host
already has, so a **private repository needs no key or token stored in kixctl**.
That is the recommended path: fewer secrets held at rest, and it composes with
tunneled/segmented networking. A public HTTPS URL also works with no credentials.

The same clone URL is used for both the poll (`git ls-remote`) and the pinned
build (`git+<clone_url>?rev=<sha>`), so once an SSH URL resolves, the whole
pipeline works over it.

> **The poller runs from the queue worker.** For an SSH URL, that worker must be
> able to reach the host non-interactively: the host key must already be in the
> user's `known_hosts` (it is, for any repository you push to), and the key must
> authenticate without a passphrase prompt. Polling forces `ssh -o BatchMode=yes`,
> so a missing key fails fast with a clear error instead of hanging. A Forgejo
> remote on a non-standard port must include the port in the URL
> (`ssh://git@git.lan.kixago.com:2222/…`).

## Adding a repository

1. **Settings → Repositories → Add repository.**
2. **Repository** — `owner/repo` as the host reports it (e.g. `kixago/demo-app`).
   This is what a webhook payload names itself by.
3. **Clone URL** — the SSH URL (recommended) or a public HTTPS URL.
4. Optional: **Name** (the stable slug for the app's address and its per-revision
   instances; blank derives it from the repository name), **Branch** (blank tracks
   the default branch), **Build attribute** (blank uses the install default),
   **Webhook secret**, **Poll** toggle and interval.

That is the minimum: a name and a clone URL. A push or the next poll deploys it.

## Webhook vs. poll

- Set a **webhook secret** to enable the push path for a repository you own. Point
  the host's webhook at the endpoint for its dialect:
  - Forgejo / Gitea / Codeberg → `POST /api/deploy/forgejo` (or `/gitea`,
    `/codeberg`), secret in the webhook config; kixctl verifies the raw-hex
    HMAC-SHA256 signature.
  - GitHub → `POST /api/deploy/github`; kixctl verifies the `sha256=` signature in
    `X-Hub-Signature-256`.
  The repository is looked up by the payload's `full_name` and verified against
  *its own* secret — one endpoint serves every repository.
- Leave the secret **blank** to deploy by poll only — the right choice for a repo
  on a host you can't (or don't want to) webhook, such as a mirror.

Both triggers deploy only the tracked branch. A push to any other branch is
acknowledged and ignored.

## Deploy now

**Deploy now** on a repository row checks it immediately and deploys its latest
commit if it isn't already running. It runs the same poll path on the queue, so
progress appears on the **Updates** tab like any other deploy. Use it to force a
retry after a failed build (a normal poll holds a commit it already tried, so it
won't hammer a broken build every minute).

## The scheduler

Polling runs from Laravel's scheduler, which needs `php artisan schedule:run`
invoked every minute. In development, run a scheduler process alongside the other
dev processes:

```
php artisan schedule:work
```

On an installed system, a one-minute systemd timer (or the appliance's own timer)
runs `schedule:run`. Set `DEPLOY_POLL_ENABLED=false` to pause all polling; webhook
pushes still work.

## What kixctl stores, and what it does not

Stored (encrypted at rest under `APP_KEY`, like the cluster certs): the optional
per-repository **webhook secret**. Not stored: any SSH deploy key or access token —
SSH authenticates through the host's existing access by design. Removing a
repository deletes its registration and configuration only; running revisions and
routes belong to the cluster and are left in place.
