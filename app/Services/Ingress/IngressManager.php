<?php

namespace App\Services\Ingress;

use App\Models\IngressSetting;
use Illuminate\Contracts\Container\Container;

/**
 * Front door for ingress. Resolves the provider the operator selected in the
 * GUI (managed | manual) and delegates to it. Callers (DeployFromPush, the
 * settings page, the future cutover flow) talk only to this — the provider swap
 * is invisible to them. No service-provider binding required; the concrete
 * providers are constructor-autowired.
 */
class IngressManager
{
    public function __construct(private Container $app) {}

    public function provider(?IngressSetting $settings = null): IngressProvider
    {
        $settings ??= IngressSetting::current();

        return match ($settings->provider) {
            'manual' => $this->app->make(ManualProvider::class),
            'edge' => $this->app->make(ManagedEdgeProvider::class),
            default => $this->app->make(ManagedDnsProvider::class),
        };
    }

    public function publish(string $app, string $instance, string $ip, int $port): void
    {
        $this->provider()->publish($app, $instance, $ip, $port);
    }

    public function withdraw(string $app): void
    {
        $this->provider()->withdraw($app);
    }

    public function syncAll(): void
    {
        $this->provider()->syncAll();
    }

    public function status(): array
    {
        return $this->provider()->status();
    }
}
