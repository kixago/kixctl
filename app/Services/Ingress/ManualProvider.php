<?php

namespace App\Services\Ingress;

use App\Models\AppRoute;
use App\Models\IngressSetting;

/**
 * For the operator who already runs their own DNS (Technitium, Unbound, …) and
 * wants to integrate rather than rely. kixctl records the same app -> revision
 * -> IP mapping (so switching back to `managed` loses nothing), but writes no
 * DNS itself. The settings GUI surfaces the current targets for the operator to
 * point their own records at. "It's on them" — by explicit choice.
 */
class ManualProvider implements IngressProvider
{
    public function publish(string $app, string $instance, string $ip, int $port): void
    {
        $settings = IngressSetting::current();

        AppRoute::query()->updateOrCreate(
            ['app' => $app],
            [
                'host' => $settings->hostFor($app),
                'live_instance' => $instance,
                'ip' => $ip,
                'port' => $port,
            ],
        );
    }

    public function withdraw(string $app): void
    {
        AppRoute::query()->where('app', $app)->delete();
    }

    public function syncAll(): void
    {
        // Nothing to assert — the operator's DNS is the source of truth here.
    }

    public function status(): array
    {
        $routes = AppRoute::query()->orderBy('app')->get();

        $detail = [];
        foreach ($routes as $r) {
            $detail[$r->host] = (string) $r->ip.' (port '.$r->port.')';
        }

        return [
            'ready' => true,
            'summary' => $routes->isEmpty()
                ? 'Manual mode — deploy an app, then point your DNS at the target shown here.'
                : 'Manual mode — point your DNS at these targets:',
            'detail' => $detail ?: ['note' => 'No apps deployed yet.'],
        ];
    }
}
