<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * No test should ever make a real outbound HTTP call, full stop - found the hard
     * way: InitiatePaymentWithProviderJob dispatches synchronously in several tests
     * (via ->afterCommit(), which DOES fire within RefreshDatabase's wrapping
     * transaction, not after it as might be assumed), and without this, every one of
     * those tests spent ~1.2s making a real, always-doomed connection attempt to
     * http://localhost/api/fake-provider/charge before the job's own catch() saved it
     * from crashing. Http::fake() with no arguments stubs every request with a generic
     * 200 by default - fast and harmless for tests that don't care about the call.
     * Tests that DO care (InitiatePaymentWithProviderJobTest, SendProviderWebhookJobTest,
     * etc.) call their own more specific Http::fake([...]) to override this.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }
}
