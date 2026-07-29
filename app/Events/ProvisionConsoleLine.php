<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One line of live subprocess output for the provisioning console.
 *
 * Rides the same public-channel-keyed-by-token rail as NetworkProvisionProgress
 * (a build log line is not sensitive; the unguessable token is the scope). It is
 * the finer-grained sibling of that event: NetworkProvisionProgress narrates the
 * phase summary ("✓ network ✓ profile ⟳ building"), while THIS carries the raw
 * kixctl-build / Incus output that the console tails when the operator expands it.
 *
 * ShouldBroadcastNow — not the queued ShouldBroadcast — because each line must
 * hit the wire immediately and in order. Queueing per line would batch and
 * reorder them, defeating the point of a live tail.
 *
 * $seq is a monotonic per-provision counter so the browser can order lines and
 * notice a gap even if a broadcast is dropped or arrives late.
 */
class ProvisionConsoleLine implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $token,   // unique per provision; scopes the channel
        public string $stream,  // 'out' | 'err'
        public string $line,    // one complete line, no trailing newline
        public int $seq = 0,    // monotonic order within this provision
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('console.'.$this->token);
    }

    public function broadcastAs(): string
    {
        return 'line';
    }
}
