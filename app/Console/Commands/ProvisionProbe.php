<?php

namespace App\Console\Commands;

use App\Events\ProvisionConsoleLine;
use App\Models\IngressSetting;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Ingress\CorednsProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * ISOLATION HARNESS for the WIRED console path — proves a real CoreDNS provision
 * streams its kixctl-build output over console.<token>, exactly as the
 * create-first Network tab will consume it, BEFORE that UI exists. (Same
 * discipline as the raw StreamProbe: prove the wired flow in isolation on the
 * real cluster before trusting a Filament page to drive it.)
 *
 * --rebuild deletes the resolver first so nix actually builds — otherwise
 * instanceExists short-circuits and there's nothing to stream. That delete +
 * re-ensure IS the "Rebuild resolver" backend the N2 tab will call, so this
 * proves that path too.
 *
 *   php artisan kixctl:provision-probe --token=probe1            # ensure (may short-circuit)
 *   php artisan kixctl:provision-probe --token=probe1 --rebuild  # force a real streaming build
 */
class ProvisionProbe extends Command
{
    protected $signature = 'kixctl:provision-probe
        {--rebuild : Delete the resolver first so a real build streams}
        {--token= : Reuse a fixed console channel token instead of a random one}
        {--cluster= : Cluster key (default: first registered)}';

    protected $description = 'Prove a real CoreDNS provision streams its console over Reverb (wired path)';

    public function handle(
        ClusterRegistry $registry,
        IncusClient $incus,
        CorednsProvisioner $provisioner,
    ): int {
        $token = (string) ($this->option('token') ?: Str::random(24));
        $settings = IngressSetting::current();

        $clusterKey = (string) ($this->option('cluster') ?? '');
        $cluster = $clusterKey !== ''
            ? $registry->find($clusterKey)
            : collect($registry->all())->first();

        if (! $cluster) {
            $this->error('No cluster registered to provision on.');

            return self::FAILURE;
        }

        $this->info("Streaming console to: console.{$token}");
        $this->line('  (subscribe in the browser to watch the real build tail live)');
        $this->newLine();

        if ($this->option('rebuild')) {
            $name = $settings->dns_instance;
            if ($incus->instanceExists($cluster, $name)) {
                $this->warn("Deleting existing resolver {$name} to force a rebuild…");
                $incus->deleteInstance($cluster, $name);
            }
        }

        $seq = 0;

        try {
            $result = $provisioner->ensure(
                $cluster,
                $settings,
                function (string $phase, string $message, array $extra = []): void {
                    $this->line("  <info>[{$phase}]</info> {$message}");
                },
                function (string $stream, string $line) use ($token, &$seq): void {
                    event(new ProvisionConsoleLine($token, $stream, $line, ++$seq));

                    $tag = $stream === 'err' ? '<fg=gray>err</>' : '<info>out</>';
                    $this->line("  {$tag} {$line}");
                },
            );
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Provision failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Resolver {$result['instance']} ready at {$result['ip']} on {$result['network']} — {$seq} console lines streamed.");

        return self::SUCCESS;
    }
}
