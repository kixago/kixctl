<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstanceOpProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $token,        // unique per operation; scopes the channel
        public string $op,           // create-snapshot | restore-snapshot | delete-snapshot
        public string $phase,        // pending | working | downloading | done | failed
        public ?string $stage = null,
        public ?int $percent = null,
        public ?string $rate = null,
        public ?string $message = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('instance-op.'.$this->token);
    }

    public function broadcastAs(): string
    {
        return 'progress';
    }
}
