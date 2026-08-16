<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Implements Authenticatable so App\Auth\ApiKeyGuard can hand a Merchant straight to
 * Laravel's normal auth plumbing (`$request->user()`, Policies, Gate) - no bespoke
 * "current merchant" helper needed anywhere else in the app. The trait's password/
 * remember-token methods are unused dead weight for us (no password, no "remember me"
 * concept for a B2B API key), but they're harmless no-ops, not something that needs
 * removing.
 */
class Merchant extends Model implements Authenticatable
{
    use AuthenticatableTrait;
    use HasFactory;

    // HasUlids overrides Eloquent's default auto-increment behaviour: it generates a ULID
    // in PHP before insert and treats the key as a non-incrementing string. See migration
    // comment for why ULID was chosen over auto-increment int / UUIDv4.
    use HasUlids;

    /**
     * Mass-assignable attributes.
     *
     * Deliberately excludes `api_key_hash` and `webhook_secret`: these are never set
     * directly from request input. They're generated internally (see generatePlainApiKey()/
     * hashApiKey()) so a caller can't do Merchant::create($request->all()) and accidentally
     * (or maliciously, via an unexpected extra field) set their own hash/secret.
     */
    protected $fillable = [
        'name',
        'active',
    ];

    /**
     * Never serialize these into arrays/JSON.
     *
     * api_key_hash is a hash, not the live secret, but there's still no legitimate reason
     * an API response should ever echo it back - and hiding it by default means a future
     * `MerchantResource` (or an accidental `return $merchant`) can't leak it by mistake.
     * webhook_secret is worse: it's the plaintext-recoverable HMAC signing key, so this one
     * hiding matters a lot.
     */
    protected $hidden = [
        'api_key_hash',
        'webhook_secret',
    ];

    protected $casts = [
        'active' => 'boolean',
        // AES-256-CBC under APP_KEY. Lets us read the plaintext back to sign outgoing
        // webhooks, while not storing it in the clear. See migration comment for the
        // hash-vs-encrypt reasoning versus api_key_hash.
        'webhook_secret' => 'encrypted',
    ];

    /**
     * Generate a new random plaintext API key.
     *
     * Only ever returned to the caller once (at creation time) - PayFlow itself never
     * stores or needs the plaintext again, only its hash (see hashApiKey()). 40 random
     * alnum chars via Str::random() is ~238 bits of entropy, which is what lets us get
     * away with a fast hash instead of bcrypt (see migration comment).
     *
     * The "pf_" prefix isn't required for security, but it's a cheap, common pattern
     * (Stripe's sk_..., GitHub's ghp_...) that makes a leaked key recognisable at a
     * glance in logs, and lets automated secret-scanners detect it.
     */
    public static function generatePlainApiKey(): string
    {
        return 'pf_'.Str::random(40);
    }

    /**
     * Hash a plaintext API key for storage/lookup.
     *
     * Deliberately a fast, deterministic hash (SHA-256), not Hash::make(). See the
     * migration comment on api_key_hash for why: this needs to support an indexed
     * `WHERE api_key_hash = ?` lookup, which a salted bcrypt hash cannot.
     */
    public static function hashApiKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * Look up a merchant by a plaintext API key presented on an incoming request.
     *
     * Used by the (not yet built) API-key auth middleware. Only matches active merchants -
     * a deactivated merchant's key must stop authenticating immediately, not just get
     * flagged after the fact.
     */
    public static function findByPlainApiKey(string $plainKey): ?self
    {
        return static::query()
            ->where('api_key_hash', static::hashApiKey($plainKey))
            ->where('active', true)
            ->first();
    }
}
