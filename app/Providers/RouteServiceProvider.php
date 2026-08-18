<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Deliberately looser (300/min) than every route-specific limiter below, and
        // ALWAYS keyed by IP in practice (see merchant-api's comment for why user()
        // is dead code here). Found live via a test: with this at 60/min - the same
        // number as merchant-api - one merchant exhausting its own 60/min bucket also
        // exhausted this IP-keyed bucket, which then blocked every OTHER merchant
        // sharing that IP too (realistic: merchants behind the same NAT/shared
        // hosting; in tests, literally everyone is 127.0.0.1). An outer defense-in-
        // depth layer has to be looser than the inner one it wraps, or it becomes the
        // accidental bottleneck instead of a backstop - see storage/docs/14-rate-limiting.md.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        // Applied AFTER auth:merchant on its route group (see routes/api.php), so
        // $request->user() is already the resolved Merchant here - unlike the 'api'
        // limiter above, which sits in the global 'api' middleware group and therefore
        // always runs BEFORE any route-level auth middleware, making its user()-based
        // key silently dead code for every authenticated route (it always falls back
        // to IP). Keying by merchant id, not IP, matters here: merchants can share an
        // IP (NAT, shared hosting) or rotate IPs, but their identity - and therefore
        // their fair share of the limit - doesn't change.
        RateLimiter::for('merchant-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Deliberately much stricter than merchant-api: every request here can trigger
        // a real OpenAI API call, i.e. real money, not just app load. 10/min is well
        // above what a human typing in the chat UI would ever hit, but low enough to
        // cap the cost of a compromised/leaked API key hammering the endpoint.
        RateLimiter::for('copilot', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // No merchant identity on this route (see VerifyProviderWebhookSignature) - IP
        // is the only key available. Looser than merchant-api because a real provider
        // legitimately retries failed/duplicate deliveries (see ProviderScenario) and
        // this shouldn't fight that.
        RateLimiter::for('provider-webhook', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
