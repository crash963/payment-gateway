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

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            // Couldn't resolve at all - refuse rather than let the HTTP client's own
            // DNS resolution (which might behave differently, e.g. following a redirect
            // to something we never validated) decide what happens.
            return false;
        }

        foreach ($ips as $ip) {
            // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE reject RFC 1918 private ranges,
            // loopback, link-local, and other reserved blocks - covers IPv4 and IPv6.
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
