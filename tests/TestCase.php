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
     * from crashing.
     *
     * Deliberately `preventStrayRequests()`, NOT a blanket `Http::fake()` with no
     * arguments - that was the original fix here, and it caused a real, silent bug:
     * Http's stub resolution takes the FIRST matching registered fake, and `merge()`
     * *appends* later registrations - so a no-args `Http::fake()` registered here in
     * setUp() (an unconditional stub that matches every request) permanently shadowed
     * every test's own more specific `Http::fake(['*' => Http::response(...)])`, no
     * matter what status/body the test asked for. Several tests were silently getting
     * this class's blanket 200 instead of their own fake and passing anyway (by
     * accident - a 200 happened to also satisfy their assertions), until one test that
     * specifically needed a 500 finally exposed it. `preventStrayRequests()` gives the
     * same safety (any request with no matching fake throws instead of hitting the
     * network) without ever registering a stub of its own to shadow anything.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
