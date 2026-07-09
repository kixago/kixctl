<?php

namespace App\Jobs;

use App\Events\InstanceOpProgress;
use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StreamInstanceOperation implements ShouldQueue
{
    use Queueable;

    /** Async lifecycle ops are not safe to auto-retry. */
    public int $tries = 1;

    /** Snapshots are quick, but restore of a large instance can run; be generous. */
    public int $timeout = 900;

    /**
     * @param  string  $op  create-snapshot | restore-snapshot | delete-snapshot
     */
    public function __construct(
        public string $token,
        public string $clusterKey,
        public string $op,
        public string $instance,
        public ?string $snapshot = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('incus');
    }

    public function handle(IncusClient $incus, ClusterRegistry $registry): void
    {
        $cluster = $registry->find($this->clusterKey);
        if (! $cluster) {
            $this->broadcastFailed('No cluster available.');

            return;
        }

        [$doing, $done] = $this->labels();

        try {
            $operationUrl = match ($this->op) {
                'create-snapshot' => $incus->startCreateSnapshot($cluster, $this->instance, (string) $this->snapshot),
                'restore-snapshot' => $incus->startRestoreSnapshot($cluster, $this->instance, (string) $this->snapshot),
                'delete-snapshot' => $incus->startDeleteSnapshot($cluster, $this->instance, (string) $this->snapshot),
                default => throw new \InvalidArgumentException("Unknown operation: {$this->op}"),
            };
        } catch (\Throwable $e) {
            $this->broadcastFailed($e->getMessage());

            return;
        }

        event(new InstanceOpProgress($this->token, $this->op, 'working', message: $doing));

        $deadline = microtime(true) + $this->timeout;

        while (true) {
            try {
                $incusOp = $incus->operation($cluster, $operationUrl);
            } catch (\Throwable $e) {
                $this->broadcastFailed($e->getMessage());

                return;
            }

            $code = (int) ($incusOp['status_code'] ?? 0);

            // Terminal: 200 success, 400 failure, 401 canceled.
            if ($code >= 200) {
                if ($code === 200) {
                    event(new InstanceOpProgress($this->token, $this->op, 'done', percent: 100, message: $done));
                    $this->notify($done, true);
                } else {
                    $err = ($incusOp['err'] ?? '') ?: 'Operation failed.';
                    $this->broadcastFailed($err);
                }

                return;
            }

            // Most snapshot ops emit no progress; restore may expose fs_progress.
            $meta = $incusOp['metadata'] ?? [];
            $p = $this->parseProgress($meta['download_progress'] ?? ($meta['fs_progress'] ?? null));

            event(new InstanceOpProgress(
                token: $this->token,
                op: $this->op,
                phase: $p ? 'downloading' : 'working',
                stage: $p['stage'] ?? null,
                percent: $p['percent'] ?? null,
                rate: $p['rate'] ?? null,
                message: $p ? null : $doing,
            ));

            if (microtime(true) > $deadline) {
                $this->broadcastFailed('Timed out waiting for the operation.');

                return;
            }

            usleep(500_000); // poll every 0.5s
        }
    }

    /** @return array{0:string,1:string} [doing, done] */
    private function labels(): array
    {
        return match ($this->op) {
            'create-snapshot' => ["Creating snapshot “{$this->snapshot}”…", "Snapshot “{$this->snapshot}” created."],
            'restore-snapshot' => ["Restoring “{$this->instance}” to “{$this->snapshot}”…", "Restored to “{$this->snapshot}”."],
            'delete-snapshot' => ["Deleting snapshot “{$this->snapshot}”…", "Snapshot “{$this->snapshot}” deleted."],
            default => ['Working…', 'Done.'],
        };
    }

    private function broadcastFailed(string $message): void
    {
        event(new InstanceOpProgress($this->token, $this->op, 'failed', message: $message));
        $this->notify($message, false);
    }

    /** Filament broadcast notification so the result reaches the user even if the panel closed. */
    private function notify(string $body, bool $success): void
    {
        if (! $this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $n = Notification::make()
            ->title($success ? 'Operation complete' : 'Operation failed')
            ->body($body);
        $success ? $n->success() : $n->danger();
        $n->broadcast($user);
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
