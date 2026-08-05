<?php

namespace App\Services\Deploy;

use App\Models\AppRoute;
use App\Services\Incus\Cluster;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\IngressManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The UPDATE / CUTOVER / REAP / REVERT lifecycle — the last open piece of P3-2.
 *
 * Every revision is an immutable `<app>-<sha7>` instance kixctl already launched
 * (DeployFromPush). This engine never builds or mutates one; it only swings the
 * live route between them and reaps the ones nobody wants anymore. It follows the
 * same manager shape as NetworkManager / ProfileManager: gated cluster mutations,
 * proven in isolation by a `kixctl:*-probe` before any UI.
 *
 * Deliberately NO revision-tracking table (decisions.md D6): what revisions exist
 * is legible from Incus itself (instances named `<app>-<sha7>`), what is LIVE is
 * App\Models\AppRoute::live_instance, and the one genuinely new fact — that a
 * revision was superseded and WHEN — is written back onto the instance as an
 * Incus config key (`user.kixctl.retired_at`), so it too stays readable from
 * `incus config show` and survives a reboot, with no kixctl-side ledger to drift.
 *
 *   landOrPublish(app, rev)  — the deploy path's promotion decision: publish the
 *                              first revision (so it's reachable), but LAND a
 *                              later one alongside without stealing live traffic.
 *   cutover(app, rev)  — ensure rev is up, resolve its IP, re-point the route via
 *                        IngressManager (one publish() = CoreDNS + edge re-render),
 *                        clear rev's retirement mark, retire the outgoing revision
 *                        (kept intact for revert; optionally stopped).
 *   revert(app, rev)   — a cutover to a prior, still-present revision.
 *   reap(app)          — delete retired revisions past the reap window (+ their
 *                        per-revision images); the live one is never touched.
 *   revisions(app)     — the surface the UI renders: every revision annotated
 *                        live / running / retired / reap-eligible, plus which
 *                        landed-but-unpromoted revision is "update ready".
 */
class DeploymentManager
{
    /**
     * Incus instance-config key that marks a superseded revision and records the
     * unix timestamp it was retired. Blank / absent = live-or-current (not retired).
     * A freeform `user.*` key: Incus never interprets it, it PATCH-merges cleanly,
     * and it reads straight back out of the instance config.
     */
    private const RETIRED_KEY = 'user.kixctl.retired_at';

    public function __construct(
        private IncusClient $incus,
        private ClusterRegistry $registry,
        private IngressManager $ingress,
    ) {}

    /**
     * The deploy path's promotion decision, called once a freshly-built revision
     * is up and has an address. Whether it goes LIVE depends on what's already
     * there:
     *
     *   - nothing live yet (first deploy of this app), OR the recorded live
     *     revision is gone, OR it IS this revision (a re-push of the same commit)
     *     -> publish, so the app is reachable by name immediately.
     *   - a DIFFERENT revision is already live -> land ALONGSIDE: leave the route
     *     untouched and let the new revision surface as "update ready" for the
     *     operator to promote with a cutover.
     *
     * This is the build-alongside half of the lifecycle (decisions.md): a push
     * never silently swings live traffic onto unproven code. Returns 'published'
     * or 'alongside' so the caller (and the landing probe) can log and assert.
     */
    public function landOrPublish(string $app, string $instance, string $ip, int $port): string
    {
        $cluster = $this->cluster();
        $live = optional(AppRoute::query()->where('app', $app)->first())->live_instance;

        $liveElsewhere = $live && $live !== $instance && $this->incus->instanceExists($cluster, $live);

        if ($liveElsewhere) {
            // Landed, not promoted: it must NOT resurrect on a reboot ahead of
            // the still-live revision, so pin autostart off until it's promoted.
            $this->setAutostart($cluster, $instance, false);

            Log::info('deploy.landed_alongside', [
                'app' => $app,
                'instance' => $instance,
                'live' => $live,
            ]);

            return 'alongside';
        }

        $this->ingress->publish($app, $instance, $ip, $port);

        // This revision is live now — pin it to come back on a reboot.
        $this->setAutostart($cluster, $instance, true);

        Log::info('deploy.published_live', [
            'app' => $app,
            'instance' => $instance,
            'ip' => $ip,
        ]);

        return 'published';
    }

    /**
     * Every revision of $app, newest first, annotated for the UI. `update_ready`
     * is the newest revision that landed but is neither live nor retired — a push
     * waiting to be promoted. Self-heals: a revision whose instance has vanished
     * simply isn't listed (the source of truth is the live cluster, not a table).
     *
     * @return array{live:?string, update_ready:?string, revisions:list<array<string,mixed>>}
     */
    public function revisions(string $app): array
    {
        $cluster = $this->cluster();
        $liveInstance = optional(AppRoute::query()->where('app', $app)->first())->live_instance;
        $reapDays = (int) config('deploy.reap.days', 7);
        $now = time();

        $list = collect($this->incus->instances($cluster))
            ->filter(fn ($i) => $this->isRevision($app, (string) $i['name']));

        $rows = [];
        foreach ($list as $listRow) {
            $name = (string) $listRow['name'];
            $raw = $this->incus->instance($cluster, $name);      // recursion=1: config, created_at, status
            $config = (array) ($raw['config'] ?? []);

            $retiredRaw = (string) ($config[self::RETIRED_KEY] ?? '');
            $retiredAt = ($retiredRaw !== '' && ctype_digit($retiredRaw)) ? (int) $retiredRaw : null;

            $createdAt = (string) ($raw['created_at'] ?? '');
            $createdTs = $createdAt !== '' ? (int) strtotime($createdAt) : 0;

            $isLive = ($name === $liveInstance);
            $running = (($raw['status'] ?? $listRow['status'] ?? '') === 'Running');

            $rows[] = [
                'instance' => $name,
                'sha' => (string) str($name)->afterLast('-'),
                'live' => $isLive,
                'running' => $running,
                'ip' => $listRow['ipv4'] ?? null,
                'node' => $listRow['node'] ?? null,
                'retired_at' => $retiredAt,
                'created_at' => $createdAt,
                'created_ts' => $createdTs,
                'reap_eligible' => (! $isLive) && $retiredAt !== null
                    && ($now - $retiredAt) >= ($reapDays * 86400),
            ];
        }

        usort($rows, fn ($a, $b) => $b['created_ts'] <=> $a['created_ts']);

        $liveTs = (int) (collect($rows)->firstWhere('live', true)['created_ts'] ?? 0);
        $updateReady = collect($rows)->first(
            fn ($r) => ! $r['live'] && $r['retired_at'] === null && $r['created_ts'] > $liveTs
        );

        return [
            'live' => $liveInstance,
            'update_ready' => $updateReady['instance'] ?? null,
            'revisions' => $rows,
        ];
    }

    /**
     * Swing the live route for $app onto $instance and retire the outgoing one.
     * Idempotent-ish: cutting over to the already-live revision just re-asserts
     * the route (no self-retirement). Throws if the target isn't a real, present
     * revision of this app, or never takes an address.
     *
     * @return array{app:string, from:?string, to:string, ip:string, retired:?string, stopped:bool}
     */
    public function cutover(string $app, string $instance): array
    {
        $cluster = $this->cluster();

        if (! $this->isRevision($app, $instance)) {
            throw new RuntimeException("‘{$instance}’ is not a revision of ‘{$app}’.");
        }
        if (! $this->incus->instanceExists($cluster, $instance)) {
            throw new RuntimeException("Revision ‘{$instance}’ no longer exists.");
        }

        $route = AppRoute::query()->where('app', $app)->first();
        $outgoing = $route?->live_instance;
        $port = (int) (($route?->port ?: 0) ?: config('ingress.app_port', 8080));

        // A retired revision may be stopped — bring it up before we route to it.
        $this->ensureRunning($cluster, $instance);

        // A fresh start can take a new lease; resolve the current address.
        $ip = $this->waitForIpv4($cluster, $instance);
        if ($ip === null) {
            throw new RuntimeException("Revision ‘{$instance}’ has no address yet — cannot cut over to it.");
        }

        // One publish = updateOrCreate(app_routes) + provider re-render (CoreDNS
        // + owned edge). Cutover latency is bounded by the resolver refresh (5s).
        $this->ingress->publish($app, $instance, $ip, $port);

        // The target is live now — clear any retirement mark it carried (revert)
        // and pin it to autostart so it survives a reboot.
        $this->setRetired($cluster, $instance, null);
        $this->setAutostart($cluster, $instance, true);

        // Retire the outgoing revision: mark it, keep it intact for revert, and
        // (by default) stop it so a superseded revision stops consuming CPU/RAM.
        $stopped = false;
        if ($outgoing && $outgoing !== $instance && $this->incus->instanceExists($cluster, $outgoing)) {
            $this->setRetired($cluster, $outgoing, time());
            $this->setAutostart($cluster, $outgoing, false);

            if ((bool) config('deploy.reap.stop_retired', true)) {
                try {
                    $this->incus->setInstanceState($cluster, $outgoing, 'stop');
                    $stopped = true;
                } catch (\Throwable $e) {
                    // Never fail a cutover because the old revision wouldn't stop.
                    Log::warning('deploy.retire_stop_failed', [
                        'instance' => $outgoing,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('deploy.cutover', [
            'app' => $app,
            'to' => $instance,
            'from' => $outgoing,
            'ip' => $ip,
            'stopped' => $stopped,
        ]);

        return [
            'app' => $app,
            'from' => $outgoing,
            'to' => $instance,
            'ip' => $ip,
            'retired' => ($outgoing && $outgoing !== $instance) ? $outgoing : null,
            'stopped' => $stopped,
        ];
    }

    /**
     * Revert = cut over to a prior revision. Mechanically identical to cutover
     * (the prior revision is a whole intact container, not a reverse migration);
     * kept as its own verb so the intent is legible in logs and the UI.
     *
     * @return array{app:string, from:?string, to:string, ip:string, retired:?string, stopped:bool}
     */
    public function revert(string $app, string $instance): array
    {
        Log::info('deploy.revert', ['app' => $app, 'to' => $instance]);

        return $this->cutover($app, $instance);
    }

    /**
     * Delete revisions of $app that were retired longer than the reap window ago,
     * together with their per-revision images. The live revision and any revision
     * still inside its window are always kept. $dryRun returns the plan without
     * deleting anything — used by the probe and to populate the confirmation.
     *
     * @return array{app:string, reaped:list<string>, kept:list<string>}
     */
    public function reap(string $app, bool $dryRun = false): array
    {
        $cluster = $this->cluster();
        $state = $this->revisions($app);

        $reaped = [];
        $kept = [];

        foreach ($state['revisions'] as $r) {
            if ($r['live'] || ! $r['reap_eligible']) {
                $kept[] = $r['instance'];

                continue;
            }

            if ($dryRun) {
                $reaped[] = $r['instance'];

                continue;
            }

            try {
                $this->incus->deleteInstance($cluster, $r['instance']);

                // The revision's image is aliased by the instance name; drop it too
                // so the immutable history stays bounded. Absent alias = nothing to do.
                $fingerprint = $this->incus->imageFingerprintByAlias($cluster, $r['instance']);
                if ($fingerprint) {
                    try {
                        $this->incus->deleteImage($cluster, $fingerprint);
                    } catch (\Throwable $e) {
                        Log::warning('deploy.reap_image_failed', [
                            'instance' => $r['instance'],
                            'fingerprint' => $fingerprint,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $reaped[] = $r['instance'];
            } catch (\Throwable $e) {
                Log::error('deploy.reap_failed', [
                    'instance' => $r['instance'],
                    'error' => $e->getMessage(),
                ]);
                $kept[] = $r['instance'];
            }
        }

        Log::info('deploy.reap', [
            'app' => $app,
            'dry_run' => $dryRun,
            'reaped' => $reaped,
            'kept' => $kept,
        ]);

        return ['app' => $app, 'reaped' => $reaped, 'kept' => $kept];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Start the instance if it isn't already running (a stopped, retired revision). */
    private function ensureRunning(Cluster $cluster, string $instance): void
    {
        $raw = $this->incus->instance($cluster, $instance);
        if (($raw['status'] ?? '') !== 'Running') {
            $this->incus->setInstanceState($cluster, $instance, 'start');
        }
    }

    /** Poll for a DHCP-leased IPv4, matching the deploy job's own wait loop. */
    private function waitForIpv4(Cluster $cluster, string $instance, int $tries = 15): ?string
    {
        for ($i = 0; $i < $tries; $i++) {
            try {
                $ip = $this->incus->instanceIpv4($cluster, $instance);
            } catch (\Throwable) {
                $ip = null;
            }

            if ($ip !== null) {
                return $ip;
            }

            sleep(1);
        }

        return null;
    }

    /**
     * Write (or blank) the retirement mark on an instance. Incus PATCH merges
     * config, so this sets only our one key; a blank value means "not retired"
     * because PATCH can't remove a key (revert blanks it rather than deleting it).
     */
    private function setRetired(Cluster $cluster, string $instance, ?int $ts): void
    {
        $this->incus->updateInstance($cluster, $instance, [
            'config' => [self::RETIRED_KEY => $ts !== null ? (string) $ts : ''],
        ]);
    }

    /**
     * Pin a revision's boot-time autostart. Exactly the live revision carries
     * boot.autostart=true; every superseded one carries false, so a host reboot
     * brings back only the live revision — never a stopped-then-manually-started
     * old one, and never an un-promoted candidate. Written at each live-ness
     * change (publish, land-alongside, cutover, revert). This ONLY ever touches
     * <slug>-<sha7> revisions the lifecycle owns; a hand-created instance is never
     * passed here, so its operator-set autostart is left alone.
     *
     * Best-effort: boot.autostart is a boot-time key (no live effect), it
     * re-asserts on the next transition, and Incus's last-state restore is a
     * safe fallback — so a transient PATCH failure logs rather than failing a
     * deploy or a cutover whose route has already moved.
     */
    private function setAutostart(Cluster $cluster, string $instance, bool $on): void
    {
        try {
            $this->incus->updateInstance($cluster, $instance, [
                'config' => ['boot.autostart' => $on ? 'true' : 'false'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('deploy.autostart_failed', [
                'instance' => $instance,
                'on' => $on,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isRevision(string $app, string $name): bool
    {
        return (bool) preg_match('/^'.preg_quote($app, '/').'-[0-9a-f]{7}$/', $name);
    }

    private function cluster(): Cluster
    {
        $key = (string) config('deploy.launch.cluster', '');
        $cluster = $key !== '' ? $this->registry->find($key) : null;
        $cluster ??= collect($this->registry->all())->first();

        if (! $cluster) {
            throw new RuntimeException('No active cluster to run the deploy lifecycle on.');
        }

        return $cluster;
    }
}
