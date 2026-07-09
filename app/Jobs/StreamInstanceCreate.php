<?php

namespace App\Jobs;

use App\Events\InstanceCreateProgress;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StreamInstanceCreate implements ShouldQueue
{
    use Queueable;

    /** A create is never safe to auto-retry; one attempt only. */
    public int $tries = 1;

    /** Generous ceiling for a large image pull on 1 gig. */
    public int $timeout = 1800;

    public function __construct(
        public string $token,
        public string $clusterKey,
        public array $payload,
        public ?string $target = null,
    ) {}

    public function handle(IncusClient $incus, ClusterRegistry $registry): void
    {
        $cluster = $registry->find($this->clusterKey);

        try {
            $operationUrl = $incus->startInstanceCreate($cluster, $this->payload, $this->target);
        } catch (\Throwable $e) {
            event(new InstanceCreateProgress($this->token, 'failed', message: $e->getMessage()));

            return;
        }

        event(new InstanceCreateProgress($this->token, 'pending', message: 'Create accepted…'));

        $deadline = microtime(true) + $this->timeout;

        while (true) {
            try {
                $op = $incus->operation($cluster, $operationUrl);
            } catch (\Throwable $e) {
                event(new InstanceCreateProgress($this->token, 'failed', message: $e->getMessage()));

                return;
            }

            $code = (int) ($op['status_code'] ?? 0);

            if ($code >= 200) { // finished: 200 ok, 400 failure, 401 canceled
                if ($code === 200) {
                    event(new InstanceCreateProgress($this->token, 'done', percent: 100, message: 'Instance ready.'));
                } else {
                    event(new InstanceCreateProgress($this->token, 'failed', message: ($op['err'] ?? '') ?: 'Operation failed.'));
                }

                return;
            }

            $p = $this->parseProgress($op['metadata']['download_progress'] ?? null);
            event(new InstanceCreateProgress(
                token: $this->token,
                phase: $p ? 'downloading' : 'creating',
                stage: $p['stage'] ?? null,
                percent: $p['percent'] ?? null,
                rate: $p['rate'] ?? null,
            ));

            if (microtime(true) > $deadline) {
                event(new InstanceCreateProgress($this->token, 'failed', message: 'Timed out waiting for create.'));

                return;
            }

            usleep(500_000); // poll every 0.5s
        }
    }

    /** "rootfs: 45% (43.21MB/s)" -> ['stage'=>'rootfs','percent'=>45,'rate'=>'43.21MB/s'] */
    private function parseProgress(?string $raw): ?array
    {
        if (! $raw || ! preg_match('/^(?<stage>[^:]+):\s*(?<pct>\d+)%\s*(?:\((?<rate>[^)]+)\))?/', $raw, $m)) {
            return null;
        }

        return [
            'stage' => trim($m['stage']),
            'percent' => (int) $m['pct'],
            'rate' => $m['rate'] ?? null,
        ];
    }
}
