<?php

namespace App\Console\Commands;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\CaddyfileRenderer;
use App\Services\Ingress\CaddyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * ISOLATION HARNESS for the owned Caddy edge — the sibling of
 * kixctl:provision-probe, proving the whole config loop on the live cluster
 * BEFORE any GUI drives it:
 *
 *   1. build + launch a THROWAWAY edge on kixbr0 + kix (its own instance, so the
 *      real kixctl-caddy and your caddy-server are never touched),
 *   2. push a Caddyfile with a `respond` route AND a `reverse_proxy` to a dead
 *      port, over the Incus files API,
 *   3. prove caddy --watch reloaded it by curling THROUGH caddy from inside the
 *      container: respond host -> 200, proxy host -> 502 (route live, upstream
 *      down), unknown host -> 404,
 *   4. push a routes-empty Caddyfile and prove the reload removed them (the host
 *      now returns the placeholder), so we know withdraw works too,
 *   5. tear the throwaway down (instance + image).
 *
 * curl runs via `incus exec` because host->bridge HTTP is unreliable (the smoke
 * test proved it) and IncusClient has no exec — a probe is a dev tool, so
 * shelling to the incus CLI for verification is fine.
 *
 *   php artisan kixctl:caddy-probe
 *   php artisan kixctl:caddy-probe --keep            # leave the edge for inspection
 *   php artisan kixctl:caddy-probe --instance=kixctl-caddy-try
 */
class CaddyProbe extends Command
{
    protected $signature = 'kixctl:caddy-probe
        {--instance=kixctl-caddy-probe : Throwaway edge instance name}
        {--keep : Do not tear the edge down after proving}';

    protected $description = 'Prove the owned Caddy edge: build, push a route, reload, reverse-proxy through it (isolation test)';

    public function handle(CaddyProvisioner $provisioner, IncusClient $incus, ClusterRegistry $registry): int
    {
        $instance = (string) $this->option('instance');
        $cluster = collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered.');

            return self::FAILURE;
        }

        $this->info("Ensuring throwaway Caddy edge '{$instance}' on kixbr0 + kix (builds on first run)…");
        try {
            $state = $provisioner->ensure($cluster, $instance,
                onProgress: function (string $phase, string $message): void {
                    $this->line("  [{$phase}] {$message}");
                },
            );
        } catch (\Throwable $e) {
            $this->error('ensure() failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Edge up: {$state['instance']} at {$state['ip']} on {$state['network']}.");

        // 2) Push a probe Caddyfile: a respond route + a reverse_proxy to a dead
        //    port. Rendered by hand (not CaddyfileRenderer) because we want the
        //    `respond` directive to prove serving without needing a live upstream.
        $probeCaddyfile = <<<'CADDY'
        # kixctl caddy-probe — throwaway
        {
        	auto_https off
        }

        http://probe.apps.internal {
        	respond "kixctl-caddy-probe-ok" 200
        }

        http://proxy.apps.internal {
        	reverse_proxy 127.0.0.1:59999
        }
        CADDY;

        $this->info('Pushing the probe Caddyfile (respond + reverse_proxy) over the Incus files API…');
        try {
            $provisioner->pushConfig($cluster, $instance, $probeCaddyfile."\n");
        } catch (\Throwable $e) {
            $this->error('pushConfig() failed: '.$e->getMessage());

            return $this->leaveUp($instance);
        }

        // caddy --watch reloads on the file change; give fsnotify + graceful
        // reload a beat before we probe.
        sleep(3);

        $this->info('Verifying THROUGH caddy (incus exec curl from inside the container)…');

        [$code, $body] = $this->curlInside($instance, 'probe.apps.internal');
        if ($code !== '200' || ! str_contains($body, 'kixctl-caddy-probe-ok')) {
            $this->error("respond route failed: expected 200/kixctl-caddy-probe-ok, got {$code} / '".trim($body)."'.");

            return $this->leaveUp($instance);
        }
        $this->info("  respond host -> 200 '{$body}'. Push + watch + reload + serve proven.");

        [$code] = $this->curlInside($instance, 'proxy.apps.internal');
        if ($code !== '502') {
            $this->error("reverse_proxy route failed: expected 502 (route live, upstream down), got {$code}.");

            return $this->leaveUp($instance);
        }
        $this->info('  proxy host -> 502 (route live, dead upstream). reverse_proxy config proven.');

        [$code] = $this->curlInside($instance, 'nope.apps.internal');
        if ($code !== '404') {
            $this->warn("  unknown host -> {$code} (expected 404; non-fatal, caddy default may differ).");
        } else {
            $this->info('  unknown host -> 404. Only declared routes are served.');
        }

        // 4) Withdraw: push the routes-empty render and prove the reload removed
        //    the probe routes (the host now hits the placeholder, not the respond).
        $this->info('Pushing an empty render (withdraw) and re-checking…');
        try {
            $provisioner->pushConfig($cluster, $instance, CaddyfileRenderer::build([]));
        } catch (\Throwable $e) {
            $this->error('withdraw push failed: '.$e->getMessage());

            return $this->leaveUp($instance);
        }
        sleep(3);
        [$code, $body] = $this->curlInside($instance, 'probe.apps.internal');
        if (str_contains($body, 'kixctl-caddy-probe-ok')) {
            $this->error('Withdraw did not take — the probe route is still served after the reload.');

            return $this->leaveUp($instance);
        }
        $this->info("  after withdraw: probe host -> {$code} '".trim($body)."' (route gone). Reload-on-change proven.");

        if ($this->option('keep')) {
            $this->warn("--keep set; leaving '{$instance}' running. Remove it with: incus delete -f {$instance} && incus image delete {$instance}");

            return self::SUCCESS;
        }

        $this->info("Tearing down '{$instance}'…");
        $this->teardown($provisioner, $cluster, $instance, success: true);

        $this->info('Clean: owned Caddy edge builds, launches, takes pushed routes, reloads live, and reverse-proxies. Proven.');

        return self::SUCCESS;
    }

    /**
     * A verification failure LEAVES the instance up so it can be inspected —
     * the whole point of a probe is to debug the failure, not erase it. Prints
     * the inspection + manual-cleanup commands and returns FAILURE.
     */
    private function leaveUp(string $instance): int
    {
        $this->newLine();
        $this->warn("Left '{$instance}' running for inspection. Look at caddy, then clean up:");
        $this->line("  incus exec {$instance} -- systemctl status caddy --no-pager -l | head -25");
        $this->line("  incus exec {$instance} -- journalctl -u caddy --no-pager -n 40");
        $this->line("  incus exec {$instance} -- cat /var/lib/kixctl-caddy/Caddyfile");
        $this->line("  incus exec {$instance} -- sh -c 'systemctl cat caddy | grep -nE \"ExecStart|ExecReload|ExecStartPre|Type=\"'");
        $this->line("  incus delete -f {$instance} && incus image delete {$instance}");

        return self::FAILURE;
    }

    /**
     * curl THROUGH caddy from inside the container. Returns [http_code, body].
     * Uses `incus exec` because the host can't reliably reach kixbr0 over HTTP;
     * from inside, localhost:80 is caddy.
     *
     * @return array{0:string, 1:string}
     */
    private function curlInside(string $instance, string $host): array
    {
        $result = Process::timeout(20)->run([
            'incus', 'exec', $instance, '--',
            'curl', '-s', '-w', "\n%{http_code}", '-H', "Host: {$host}", 'http://localhost/',
        ]);

        $out = $result->output();
        $nl = strrpos($out, "\n");
        if ($nl === false) {
            return ['000', trim($out)];
        }

        $body = substr($out, 0, $nl);
        $code = trim(substr($out, $nl + 1));

        return [$code !== '' ? $code : '000', $body];
    }

    /** Delete the throwaway edge + its image. */
    private function teardown(CaddyProvisioner $provisioner, $cluster, string $instance, bool $success = false): int
    {
        try {
            $provisioner->teardown($cluster, $instance);
        } catch (\Throwable $e) {
            $this->warn("Teardown of '{$instance}' failed: {$e->getMessage()} — remove it by hand.");
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
