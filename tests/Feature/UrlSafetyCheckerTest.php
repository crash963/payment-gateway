<?php

namespace Tests\Feature;

use App\Services\UrlSafetyChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature, not Unit: UrlSafetyChecker reads config('webhooks.allow_private_urls'),
 * which needs a booted app container - calling it from a plain PHPUnit\TestCase
 * (no Laravel bootstrap) would fatal on the `config()` helper itself. No database is
 * touched here, so no RefreshDatabase - the app boot is the only thing this needs.
 *
 * Uses IP literals throughout, not hostnames - a hostname would require a real DNS
 * lookup (gethostbynamel()), which has no place in a test.
 */
class UrlSafetyCheckerTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1/hook'],
            'loopback IPv6' => ['http://[::1]/hook'],
            'private RFC1918 10.x' => ['http://10.0.0.5/hook'],
            'private RFC1918 192.168.x' => ['http://192.168.1.5/hook'],
            'link-local (cloud metadata endpoint)' => ['http://169.254.169.254/hook'],
            'non-http(s) scheme' => ['file:///etc/passwd'],
            'no host at all' => ['not-a-url'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected_when_private_urls_are_not_allowed(string $url): void
    {
        config(['webhooks.allow_private_urls' => false]);

        $this->assertFalse((new UrlSafetyChecker)->isSafe($url));
    }

    public function test_a_public_ip_is_allowed(): void
    {
        config(['webhooks.allow_private_urls' => false]);

        $this->assertTrue((new UrlSafetyChecker)->isSafe('http://8.8.8.8/hook'));
    }

    public function test_the_allow_private_urls_escape_hatch_permits_a_loopback_url(): void
    {
        config(['webhooks.allow_private_urls' => true]);

        $this->assertTrue((new UrlSafetyChecker)->isSafe('http://127.0.0.1/hook'));
    }
}
