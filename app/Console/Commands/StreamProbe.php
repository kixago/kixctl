<?php

namespace App\Console\Commands;

use App\Events\ProvisionConsoleLine;
use App\Support\ConsoleStreamer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * ISOLATION HARNESS for the N2 collapsible console — proves the raw line-by-line
 * subprocess streaming works on the real cluster BEFORE any Filament view wires
 * it in. (The N1 slog was wiring-before-proving; this is the deliberate counter.)
 *
 * Two witnesses at once:
 *   1. lines echo to THIS terminal as they arrive       -> incremental capture works
 *   2. each line fires ProvisionConsoleLine over Reverb  -> broadcast works
 * Subscribe a browser to `console.<token>` (the token is printed up front) and
 * the same lines should land live — no Filament view required to trust the rail.
 *
 *   php artisan kixctl:stream-probe               # synthetic emitter (no nix)
 *   php artisan kixctl:stream-probe --cmd="..."   # any command, via bash -lc
 *   php artisan kixctl:stream-probe --build       # the REAL kixctl-build (nix)
 */
class StreamProbe extends Command
{
    protected $signature = 'kixctl:stream-probe
        {--cmd= : Arbitrary command to stream (run via bash -lc)}
        {--build : Stream the real scripts/kixctl-build instead of the synthetic emitter}
        {--flake= : Flake ref for --build (default: config ingress.managed.flake)}
        {--attr=coredns : Flake attr for --build}
        {--token= : Reuse a fixed channel token instead of a random one}
        {--timeout=1800 : Process timeout in seconds}';

    protected $description = 'Prove raw line-by-line subprocess streaming + Reverb broadcast in isolation';

    public function handle(ConsoleStreamer $streamer): int
    {
        $token = (string) ($this->option('token') ?: Str::random(24));
        $command = $this->probeCommand();

        $this->info("Streaming to channel: console.{$token}");
        $this->line('  (subscribe in the browser to watch it land over Reverb)');
        $this->line('  <comment>'.$this->describe($command).'</comment>');
        $this->newLine();

        $seq = 0;
        $counts = ['out' => 0, 'err' => 0];

        $result = $streamer->run(
            $command,
            function (string $stream, string $line) use ($token, &$seq, &$counts): void {
                $counts[$stream]++;
                event(new ProvisionConsoleLine($token, $stream, $line, ++$seq));

                // Local witness: stderr dim, stdout highlighted — mirrors what the
                // console will show (build logs on err, JSON result on out).
                $tag = $stream === 'err' ? '<fg=gray>err</>' : '<info>out</>';
                $this->line("  {$tag} {$line}");
            },
            (int) $this->option('timeout'),
        );

        $this->newLine();
        $this->info("exit={$result->exitCode()}  lines: {$counts['out']} out / {$counts['err']} err  seq={$seq}");

        // For a real build, stdout should be the single JSON result line — parsing
        // it proves the out/err split held under streaming (logs never leaked in).
        $stdout = trim($result->output());
        if ($stdout !== '') {
            $json = json_decode($stdout, true);
            if (is_array($json)) {
                $this->info('stdout parsed as JSON — out/err split intact: '.implode(', ', array_keys($json)));
            } else {
                $this->warn('stdout was non-empty and NOT JSON (fine for a bare --cmd; a red flag for --build):');
                $this->line('  '.Str::limit($stdout, 200));
            }
        }

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int,string> */
    private function probeCommand(): array
    {
        if ($this->option('build')) {
            $flake = (string) ($this->option('flake') ?: config('ingress.managed.flake'));
            $attr = (string) ($this->option('attr') ?: config('ingress.managed.flake_attr', 'coredns'));

            return [base_path('scripts/kixctl-build'), '--flake', $flake, '--attr', $attr, '--kind', 'container'];
        }

        if ($cmd = $this->option('cmd')) {
            return ['bash', '-lc', (string) $cmd];
        }

        // Default synthetic emitter, faithful to the kixctl-build contract: build
        // "logs" go to stderr (streamed, spaced 0.5s so you SEE them arrive live,
        // not batched), and the single JSON result lands on stdout at the end.
        $script = 'for i in $(seq 1 8); do echo "build step $i / 8" >&2; sleep 0.5; done; '
            .'echo \'{"metadata":"/nix/store/demo-metadata","rootfs":"/nix/store/demo-rootfs"}\'';

        return ['bash', '-lc', $script];
    }

    /** @param array<int,string> $command */
    private function describe(array $command): string
    {
        return implode(' ', array_map(
            fn (string $p): string => str_contains($p, ' ') ? '"'.$p.'"' : $p,
            $command,
        ));
    }
}
