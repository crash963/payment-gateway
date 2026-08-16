<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Auth\ApiKeyGuard;
use App\Models\Payment;
use App\Policies\PaymentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * Laravel would actually auto-discover this via naming convention
     * (App\Models\Payment -> App\Policies\PaymentPolicy) without registering it here -
     * kept explicit anyway, it costs one line and removes any doubt about which policy
     * governs which model when skimming this file.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Payment::class => PaymentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Makes the "api-key" driver available for the "merchant" guard in
        // config/auth.php. Guard::extend() is given the container and the guard's own
        // config array, but ApiKeyGuard needs neither - it resolves the request from
        // the container directly.
        Auth::extend('api-key', fn ($app) => new ApiKeyGuard($app['request']));
    }
}
