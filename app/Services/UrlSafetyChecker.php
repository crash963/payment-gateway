<?php

namespace App\Services;

/**
 * SSRF guard for any URL PayFlow is about to make an outbound HTTP request to on a
 * merchant's behalf (currently: merchant webhook delivery). Format validation at input
 * time ('url' Laravel validation rule) is necessary but not sufficient - it doesn't
 * examine WHERE the URL actually points, and DNS can change between when a merchant
 * saved the URL and when we call it (TOCTOU), so this check happens right before the
 * outbound call, not just once at input time.
 *
 * A malicious/compromised merchant account could otherwise set webhook_url to
 * something like http://169.254.169.254/... (cloud metadata endpoints) or an internal
 * service address, and use PayFlow as a proxy to reach network locations it couldn't
 * reach directly - PayFlow's own server making the request on its behalf.
 */
class UrlSafetyChecker
{
    public function isSafe(string $url): bool
    {
        if ((bool) config('webhooks.allow_private_urls')) {
            return true;
        }

        return $this->resolveValidatedIp($url) !== null;
    }

    /**
     * The specific IP isSafe() validated for $url - callers that actually make the
     * outbound request (DeliverMerchantWebhookJob) should connect to THIS literal IP
     * rather than letting the HTTP client re-resolve the hostname on its own.
     *
     * Found in code review: isSafe() and the eventual Http::post($url) call used to
     * resolve DNS independently, moments apart. A domain with a very short TTL that
     * answers a public IP on the first lookup and a private/loopback/metadata IP on the
     * next (DNS rebinding) would pass this check and then have the actual connection go
     * somewhere never validated - the two lookups aren't guaranteed to agree. Resolving
     * once here and pinning the real request to that address (via curl's CURLOPT_RESOLVE
     * - see DeliverMerchantWebhookJob) closes that gap: the IP that was checked is the
     * IP that's connected to, not just "a" IP the same hostname happened to resolve to
     * at some point.
     *
     * Returns null when there's nothing meaningful to pin: the URL is unsafe/
     * unresolvable (caller should already be refusing via isSafe() by then), or
     * allow_private_urls bypassed validation entirely (nothing was actually checked, so
     * pinning would be pinning to an unvalidated address - just let the HTTP client
     * resolve normally, consistent with that flag being a deliberately permissive local/
     * demo escape hatch).
     */
    public function resolveValidatedIp(string $url): ?string
    {
        if ((bool) config('webhooks.allow_private_urls')) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            // Couldn't resolve at all - refuse rather than let the HTTP client's own
            // DNS resolution (which might behave differently, e.g. following a redirect
            // to something we never validated) decide what happens.
            return null;
        }

        foreach ($ips as $ip) {
            // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE reject RFC 1918 private ranges,
            // loopback, link-local, and other reserved blocks - covers IPv4 and IPv6.
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $ips[0];
    }
}
