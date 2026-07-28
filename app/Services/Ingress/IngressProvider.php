<?php

namespace App\Services\Ingress;

/**
 * A pluggable ingress backend. The default (ManagedDnsProvider) auto-provisions
 * and drives a CoreDNS resolver kixctl owns; ManualProvider records the same
 * mapping but leaves the DNS write to an operator who runs their own. Both share
 * one data model (App\Models\AppRoute) so switching providers never loses state.
 */
interface IngressProvider
{
    /**
     * Make $app reachable at its stable host, pointing at $instance / $ip.
     * Idempotent: called on every (re)deploy and on cutover.
     */
    public function publish(string $app, string $instance, string $ip, int $port): void;

    /** Remove $app from ingress (reap). Idempotent. */
    public function withdraw(string $app): void;

    /**
     * Re-assert the full current state from App\Models\AppRoute. Used after
     * "back to defaults", after (re)provisioning, and to self-heal drift.
     */
    public function syncAll(): void;

    /**
     * Human-facing status for the settings GUI: whether the backend is ready,
     * how it's reached, and anything the operator must do themselves (manual).
     *
     * @return array{ready:bool, summary:string, detail:array<string,string>}
     */
    public function status(): array;
}
