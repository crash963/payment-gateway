<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Never redirect - this is a JSON-only API with no login page (see the "merchant"
     * default guard in config/auth.php). The skeleton's original `expectsJson() ? null :
     * route('login')` crashed with a RouteNotFoundException for any request that didn't
     * explicitly send an `Accept: application/json` header (curl without -H, a browser
     * hitting the URL directly, etc.) - `expectsJson()` is false in those cases, so it
     * fell through to a login route that was never defined. Found by manually curling
     * the endpoint without headers; the test suite's postJson() helper always sets that
     * header, so it never exercised this path.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
