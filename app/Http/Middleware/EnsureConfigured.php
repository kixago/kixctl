<?php

namespace App\Http\Middleware;

use App\Models\InstanceSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force first boot through the setup wizard. Until instance_settings.configured_at
 * is set, every panel request is bounced to the wizard at /. Once configured this
 * is a no-op. It pairs with SetupWizard::mount, which sends configured users the
 * other way (/ -> /admin) — together they are the first-run gate, so the appliance
 * can't be driven with the seeded default credentials before the operator sets
 * their own.
 */
class EnsureConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! InstanceSetting::current()->isConfigured()) {
            return redirect('/');
        }

        return $next($request);
    }
}
