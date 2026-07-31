<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live progress for a push-triggered deploy (DeployFromPush), so the open Updates
 * tab reflects a build it did NOT initiate instead of sitting on stale state until
 * a manual refresh.
 *
 * Unlike the network/snapshot streams — keyed by an unguessable token the INITIATING
 * component generates and subscribes to — a deploy is started by a webhook, not by
 * the tab, so the tab has no token to listen on. This therefore rides ONE stable
 * public channel, `deploys`, that the tab subscribes to unconditionally. A deliberate
 * departure from the token pattern: the payload is only an app key, a short sha, and
 * a phase — not sensitive — the control plane's Reverb is behind auth and never
 * internet-facing, and a well-known channel is what lets the tab catch even the
 * first-ever deploy of an app that has no card yet.
 *
 * ShouldBroadcastNow (not queued): the job already runs on the queue, so the progress
 * must fire synchronously from inside it — same choice as NetworkProvisionProgress.
 */
class DeployProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $app,          // stable app key (the route/card this belongs to)
        public string $phase,        // building|published|landed|failed
        public string $instance,     // <app>-<sha7> for this revision
        public ?string $sha = null,  // short sha, for the banner
        public ?string $message = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('deploys');
    }

    public function broadcastAs(): string
    {
        return 'progress';
    }
}
