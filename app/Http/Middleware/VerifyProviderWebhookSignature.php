<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards POST /api/provider/webhook - this route has no merchant to authenticate (it's
 * the provider calling PayFlow, not a merchant), so trust here comes entirely from the
 * HMAC signature instead of the "merchant" guard. This is the INVALID_CALLBACK
 * scenario's rejection point (see SendProviderWebhookJob's $useInvalidSignature).
 */
class VerifyProviderWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Provider-Signature');

        // Signed over the exact raw body bytes (getContent()), not a re-parsed/
        // re-encoded version of it - matches how SendProviderWebhookJob computed the
        // signature on the sending side. Verifying against a re-serialized copy would
        // make this fragile to incidental formatting differences that have nothing to
        // do with whether the payload is genuine.
        $expected = hash_hmac('sha256', $request->getContent(), (string) config('services.fake_provider.webhook_secret'));

        // hash_equals(), not ===: a plain string comparison short-circuits on the first
        // mismatched byte, which leaks (via response timing) how many leading
        // characters of the guess were correct - a timing side-channel an attacker
        // could use to brute-force the signature byte by byte. hash_equals() always
        // takes the same time regardless of where the strings first differ.
        if (! $signature || ! hash_equals($expected, $signature)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_signature',
                    'message' => 'Invalid provider webhook signature.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
