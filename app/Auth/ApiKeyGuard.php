<?php

namespace App\Auth;

use App\Models\Merchant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Resolves the current Merchant from an `Authorization: Bearer <api_key>` header.
 * Registered as the "api-key" driver in AuthServiceProvider and wired to the
 * "merchant" guard in config/auth.php (which is also the app's default guard - see
 * that file for why).
 *
 * Deliberately doesn't go through Laravel's UserProvider abstraction (the "provider"
 * config guards normally have): that layer exists to support swappable storage
 * backends for looking up a user by credentials, which doesn't add anything here -
 * Merchant::findByPlainApiKey() already IS the lookup, hashing included. Adding a
 * UserProvider on top would be an extra layer of indirection with no second
 * implementation it's abstracting over.
 *
 * Testing gotcha (see RateLimitingTest): $resolved/$merchant are cached on this
 * INSTANCE, and Laravel's AuthManager caches that instance for the lifetime of the
 * container - fine in real deployment (fresh container per request, no Octane - see
 * storage/docs/00-stack-decisions.md), but Laravel's HTTP test helpers reuse the SAME
 * container across every $this->getJson() call within one test method. A test that
 * switches Authorization headers to simulate a DIFFERENT merchant mid-method must call
 * auth()->forgetGuards() in between, or it silently keeps authenticating as whichever
 * merchant resolved first.
 */
class ApiKeyGuard implements Guard
{
    protected bool $resolved = false;

    protected ?Merchant $merchant = null;

    public function __construct(protected Request $request) {}

    public function user(): ?Authenticatable
    {
        // Cached per-request: the guard can be asked `check()`/`user()`/`id()`
        // multiple times during one request (middleware, then controller, then a
        // Policy) - only hit the DB once.
        if ($this->resolved) {
            return $this->merchant;
        }

        $this->resolved = true;

        $key = $this->request->bearerToken();

        return $this->merchant = $key !== null
            ? Merchant::findByPlainApiKey($key)
            : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Not meaningful for a stateless bearer-token guard the way it is for password
     * guards (there's no separate "check these credentials without logging in" step -
     * presenting the key IS the login), so this just mirrors check().
     */
    public function validate(array $credentials = []): bool
    {
        return $this->check();
    }

    public function hasUser(): bool
    {
        return $this->merchant !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        if (! $user instanceof Merchant) {
            throw new InvalidArgumentException(self::class.' only accepts a Merchant.');
        }

        $this->merchant = $user;
        $this->resolved = true;
    }
}
