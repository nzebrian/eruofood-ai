<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

/**
 * Base test case. Laravel 11+ resolves the application from bootstrap/app.php
 * automatically, so no CreatesApplication trait is required.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate limits, quotas and RBAC permission lookups are all cache-backed:
        // Laravel's `throttle` middleware writes hit counters through the default
        // cache store, as do the PublicApi CacheRateLimiter and CacheQuotaStore.
        // RefreshDatabase rolls back the database between tests but never touches
        // the cache. With a persistent store (CI runs CACHE_STORE=redis against a
        // real Redis service) those counters survive from one test to the next and
        // accumulate; the whole suite executes in well under a throttle window, so
        // the counters never expire and later tests receive spurious HTTP 429s.
        // Flushing here gives every test a clean limiter/quota/permission state
        // regardless of the configured cache driver, so the suite is deterministic
        // on both the array store (local default) and Redis (CI).
        Cache::flush();
    }
}
