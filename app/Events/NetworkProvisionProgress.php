<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live progress for managed-network provisioning (the kixbr0 + CoreDNS stand-up).
 * Rides the same Reverb rail as the snapshot/create streams: a public channel
 * keyed by an unguessable token — no channel auth, and a provisioning step is
 * not sensitive. The GUI corner toast subscribes and narrates the real steps
 * (creating kixbr0 → building → launching → leasing → serving at <ip>) so the
 * user is never staring at a frozen spinner.
 */
class NetworkProvisionProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $token,          // unique per provision; scopes the channel
        public string $phase,          // pending|ensuring-network|building|importing|launching|starting|leasing|serving|done|failed
        public ?string $message = null,
        public ?string $ip = null,     // set on serving/done
        public ?string $network = null // the network key being provisioned
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('network-provision.'.$this->token);
    }

    public function broadcastAs(): string
    {
        return 'progress';
    }
}
