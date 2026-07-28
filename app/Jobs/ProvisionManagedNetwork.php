<?php

namespace App\Jobs;

use App\Events\NetworkProvisionProgress;
use App\Models\IngressSetting;
use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Ingress\CorednsProvisioner;
use App\Services\Ingress\IngressManager;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Provisions the kixctl-managed network (kixbr0) and the CoreDNS resolver that
 * rides it, streaming each step over Reverb so the GUI shows a live corner toast
 * instead of a frozen spinner. Replaces the old silent, synchronous safeSync()
 * on the settings page: a queued job with no visible progress is worse than
 * useless, so this narrates creating → building → launching → leasing → serving.
 *
 * After the resolver is up it re-asserts the full zone from the current routes
 * (IngressManager::syncAll), exactly as the synchronous path did — so a save
 * never loses records. Lives on the long-timeout `incus` lane: a first build is
 * a full nix build + image pull.
 */
class ProvisionManagedNetwork implements ShouldQueue
{
    use Queueable;

    /** Provisioning is not safe to auto-retry; one attempt only. */
    public int $tries = 1;

    /** Generous ceiling: a first build is a nix build + image import on 1 gig. */
    public int $timeout = 1800;

    public function __construct(
        public string $token,
        public ?string $clusterKey = null,
        public ?int $userId = null,
    ) {
        $this->onQueue('incus');
    }

    public function handle(
        ClusterRegistry $registry,
        CorednsProvisioner $provisioner,
        IngressManager $ingress,
    ): void {
        $settings = IngressSetting::current();

        // Manual provider: there is no kixctl-managed network to stand up.
        if (! $settings->isManaged()) {
            event(new NetworkProvisionProgress($this->token, 'done', 'Manual provider — nothing to provision.'));

            return;
        }

        $cluster = $this->clusterKey !== null && $this->clusterKey !== ''
            ? $registry->find($this->clusterKey)
            : collect($registry->all())->first();

        if (! $cluster) {
            $this->broadcastFailed('No active cluster to provision on.');

            return;
        }

        event(new NetworkProvisionProgress($this->token, 'pending', 'Starting…'));

        try {
            $result = $provisioner->ensure(
                $cluster,
                $settings,
                function (string $phase, string $message, array $extra = []): void {
                    event(new NetworkProvisionProgress(
                        token: $this->token,
                        phase: $phase,
                        message: $message,
                        ip: $extra['ip'] ?? null,
                        network: $extra['network'] ?? null,
                    ));
                },
            );

            // Resolver is up — re-assert the full zone from the current routes.
            $ingress->syncAll();

            event(new NetworkProvisionProgress(
                token: $this->token,
                phase: 'done',
                message: "{$result['network']} ready — resolver serving at {$result['ip']}.",
                ip: $result['ip'],
                network: $result['network'],
            ));

            $this->notify('Network ready', "{$result['network']} up; resolver at {$result['ip']}.", true);
        } catch (\Throwable $e) {
            $this->broadcastFailed($e->getMessage());
        }
    }

    private function broadcastFailed(string $message): void
    {
        event(new NetworkProvisionProgress($this->token, 'failed', $message));
        $this->notify('Provisioning failed', $message, false);
    }

    /** Filament broadcast so the outcome reaches the user even if the page closed. */
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
