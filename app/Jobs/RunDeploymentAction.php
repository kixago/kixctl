<?php

namespace App\Jobs;

use App\Events\NetworkProvisionProgress;
use App\Models\User;
use App\Services\Deploy\DeploymentManager;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Run a cutover or revert off the request thread, streaming a corner toast on the
 * same Reverb rail as the network/ingress provisioners so the Updates tab never
 * locks up. Cutover isn't a build — but it can start a stopped, retired revision
 * (revert) and then re-render the edge, which is a few seconds of real work, so it
 * gets the same live spinner the rest of the app uses rather than a frozen button.
 *
 * Both verbs delegate to DeploymentManager (cutover / revert); the manager owns
 * the route swing, the retirement mark, and the edge re-publish. This job only
 * narrates progress and reports the outcome. Lives on the `incus` lane.
 */
class RunDeploymentAction implements ShouldQueue
{
    use Queueable;

    /** A route swing is not safe to auto-retry blindly; one attempt only. */
    public int $tries = 1;

    /** Headroom to start a stopped revision, wait for its lease, and re-render the edge. */
    public int $timeout = 300;

    public function __construct(
        public string $token,
        public ?int $userId,
        public string $action,   // 'cutover' | 'revert'
        public string $app,
        public string $instance,
    ) {
        $this->onQueue('incus');
    }

    public function handle(DeploymentManager $deployment): void
    {
        $reverting = $this->action === 'revert';
        $verb = $reverting ? 'Reverting' : 'Cutting over';

        event(new NetworkProvisionProgress($this->token, 'pending', "{$verb} to {$this->instance}…"));

        try {
            $result = $reverting
                ? $deployment->revert($this->app, $this->instance)
                : $deployment->cutover($this->app, $this->instance);

            $message = "{$this->app} is now serving {$result['to']}"
                .($result['retired'] ? " · previous revision {$result['retired']} retired" : '');

            event(new NetworkProvisionProgress(
                token: $this->token,
                phase: 'done',
                message: $message,
                ip: $result['ip'],
            ));

            $this->notify(($reverting ? 'Revert' : 'Cutover').' complete', $message, true);
        } catch (\Throwable $e) {
            $human = Str::limit($e->getMessage(), 160);

            Log::error('deploy.action_failed', [
                'action' => $this->action,
                'app' => $this->app,
                'instance' => $this->instance,
                'exception' => $e,
            ]);

            event(new NetworkProvisionProgress($this->token, 'failed', $human));
            $this->notify(($reverting ? 'Revert' : 'Cutover').' failed', $human, false);
        }
    }

    private function notify(string $title, string $body, bool $success): void
    {
        if (! $this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $n = Notification::make()->title($title)->body($body);
        $success ? $n->success() : $n->danger();
        $n->broadcast($user);
    }
}
