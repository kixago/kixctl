<?php

namespace App\Jobs;

use App\Events\InstanceCreateProgress;
use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Filament\Notifications\Notification;
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
        public ?int $userId = null,
        public bool $startNow = false,
    ) {
        // Dedicated long-timeout lane; keeps slow pulls off the snappy default queue.
        $this->onQueue('incus');
    }

    public function handle(IncusClient $incus, ClusterRegistry $registry): void
    {
        $name = $this->payload['name'] ?? 'instance';
        $cluster = $registry->find($this->clusterKey);

        if (! $cluster) {
            $this->broadcastFailed('No cluster available.');
            $this->notify('Create failed', 'No cluster available.', false);

            return;
        }

        try {
            $operationUrl = $incus->startInstanceCreate($cluster, $this->payload, $this->target);
        } catch (\Throwable $e) {
            $this->broadcastFailed($e->getMessage());
            $this->notify('Create failed', $e->getMessage(), false);

            return;
        }

        event(new InstanceCreateProgress($this->token, 'pending', message: 'Create accepted…'));

        $deadline = microtime(true) + $this->timeout;

        while (true) {
            try {
                $op = $incus->operation($cluster, $operationUrl);
            } catch (\Throwable $e) {
                $this->broadcastFailed($e->getMessage());
                $this->notify('Create failed', $e->getMessage(), false);

                return;
            }

            $code = (int) ($op['status_code'] ?? 0);

            // Terminal: 200 success, 400 failure, 401 canceled.
            if ($code >= 200) {
                if ($code !== 200) {
                    $err = ($op['err'] ?? '') ?: 'Operation failed.';
                    $this->broadcastFailed($err);
                    $this->notify('Create failed', $err, false);

                    return;
                }

                // Created. Optionally start it.
                if ($this->startNow) {
                    event(new InstanceCreateProgress($this->token, 'starting', message: 'Starting instance…'));
                    try {
                        $incus->setInstanceState($cluster, $name, 'start');
                    } catch (\Throwable $e) {
                        event(new InstanceCreateProgress($this->token, 'done', percent: 100, message: 'Created, but failed to start: '.$e->getMessage()));
                        $this->notify("Instance '{$name}' created", 'Created, but failed to start: '.$e->getMessage(), false);

                        return;
                    }
                }

                event(new InstanceCreateProgress($this->token, 'done', percent: 100, message: 'Instance ready.'));
                $this->notify("Instance '{$name}' ready", $this->startNow ? 'Created and started.' : 'Created (not started).', true);

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
                $this->broadcastFailed('Timed out waiting for create.');
                $this->notify('Create timed out', "Instance '{$name}' did not finish in time.", false);

                return;
            }

            usleep(500_000); // poll every 0.5s
        }
    }

    private function broadcastFailed(string $message): void
    {
        event(new InstanceCreateProgress($this->token, 'failed', message: $message));
    }

    /**
     * Filament broadcast notification so the outcome reaches the user even if the
     * create panel was closed. Goes to the user's private channel (already proven).
     */
    private function notify(string $title, string $body, bool $success): void
    {
        if (! $this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $notification = Notification::make()->title($title)->body($body);
        $success ? $notification->success() : $notification->danger();
        $notification->broadcast($user);
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
