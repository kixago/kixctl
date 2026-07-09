<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstanceCreateProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $token,        // unique per create; scopes the channel
        public string $phase,        // pending | downloading | creating | done | failed
        public ?string $stage = null,   // 'rootfs' | 'metadata'
        public ?int $percent = null,    // 0-100, null when unknown
        public ?string $rate = null,    // '43.21MB/s'
        public ?string $message = null, // human line / error text
    ) {}

    public function broadcastOn(): Channel
    {
        // Public channel keyed by an unguessable token — no channel auth needed,
        // and progress data (percentages) isn't sensitive.
        return new Channel('instance-create.'.$this->token);
    }

    public function broadcastAs(): string
    {
        return 'progress';
    }
}
