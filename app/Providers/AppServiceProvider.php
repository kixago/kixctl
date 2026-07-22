<?php

namespace App\Providers;

use App\Services\Licensing\Entitlements;
use App\Services\Licensing\LicenseVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Offline license verification (monetization fence #2). Singletons:
        // the license file is read at most once per request.
        $this->app->singleton(LicenseVerifier::class, fn () => new LicenseVerifier(
            config('license.public_key'),
        ));

        $this->app->singleton(Entitlements::class, fn ($app) => new Entitlements(
            verifier: $app->make(LicenseVerifier::class),
            licensePath: config('license.path'),
            freeClusterCap: (int) config('license.free_cluster_cap'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
