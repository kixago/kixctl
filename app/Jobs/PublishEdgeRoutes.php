<?php

namespace App\Jobs;

use App\Events\NetworkProvisionProgress;
use App\Events\ProvisionConsoleLine;
use App\Models\IngressSetting;
use App\Models\User;
use App\Services\Ingress\IngressManager;
use App\Services\Ingress\ManagedEdgeProvider;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish the current ingress records asynchronously, streaming each step over
 * the same Reverb rail as ProvisionManagedNetwork so the records GUI shows a live
 * spinner + build console instead of locking up on save. This is the "on save,
 * update CoreDNS + the caddy config, with a spinner" path.
 *
 * For the `edge` provider it drives ManagedEdgeProvider::render() with progress +
 * console callbacks (ensure caddy + resolver, push both artifacts) — the first
 * publish is a full image build, so the console tails it exactly like the
 * resolver build. For any other provider it falls back to a plain syncAll() and
 * a single done event (nothing to stream). Lives on the long-timeout `incus`
 * lane: a first edge build is a nix build + image import.
 */
class PublishEdgeRoutes implements ShouldQueue
{
    use Queueable;

    /** Publishing is not safe to auto-retry; one attempt only. */
    public int $tries = 1;

    /** Generous ceiling: a first edge build is a nix build + image import on 1 gig. */
    public int $timeout = 1800;

    public function __construct(
        public string $token,
        public ?int $userId = null,
    ) {
        $this->onQueue('incus');
    }

    public function handle(IngressManager $ingress): void
    {
        $settings = IngressSetting::current();
        $provider = $ingress->provider($settings);

        event(new NetworkProvisionProgress($this->token, 'pending', 'Publishing…'));

        try {
            if ($provider instanceof ManagedEdgeProvider) {
                $consoleSeq = 0;
                $provider->render(
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
                    function (string $stream, string $line) use (&$consoleSeq): void {
                        // Tail BUILD LOGS (stderr) only; stdout carries JSON results.
                        if ($stream === 'err') {
                            event(new ProvisionConsoleLine($this->token, $stream, $line, ++$consoleSeq));
                        }
                    },
                );
            } else {
                // Managed-DNS / manual: no streamed build, just re-assert.
                $provider->syncAll();
            }

            event(new NetworkProvisionProgress(
                token: $this->token,
                phase: 'done',
                message: 'Ingress published.',
            ));

            $this->notify('Ingress published', 'CoreDNS and the edge are up to date.', true);
        } catch (\Throwable $e) {
            Log::error('ingress.edge.publish_failed', [
                'token' => $this->token,
                'exception' => $e,
            ]);

            $this->broadcastFailed($this->humanize($e));
        }
    }

    /** A short, human-readable failure line — never a raw Incus JSON envelope. */
    private function humanize(\Throwable $e): string
    {
        $msg = $e->getMessage();

        $brace = strpos($msg, '{');
        if ($brace !== false) {
            $json = json_decode(substr($msg, $brace), true);
            if (is_array($json) && isset($json['error']) && $json['error'] !== '') {
                $code = $json['error_code'] ?? null;

                return $code ? "Incus: {$json['error']} ({$code})" : "Incus: {$json['error']}";
            }
        }

        return \Illuminate\Support\Str::limit($msg, 160);
    }

    private function broadcastFailed(string $message): void
    {
        event(new NetworkProvisionProgress($this->token, 'failed', $message));
        $this->notify('Publish failed', $message, false);
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
