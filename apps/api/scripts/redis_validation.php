<?php

declare(strict_types=1);

/**
 * Milestone 18 — real Redis validation for the Public API's Redis-backed
 * primitives (rate limiter, quota store, distributed counters, cache) run
 * against a live Redis instance (not the array store). Boots the Laravel
 * container with cache.default=redis and drives the real adapters.
 *
 * Concurrency is exercised by forking the increment across many OS processes
 * (see redis_concurrency_worker.php), which proves the counter is atomic under
 * true parallelism — something the array store can never demonstrate.
 *
 * Run: php scripts/redis_validation.php
 * Requires: a reachable Redis (REDIS_HOST/REDIS_PORT, default 127.0.0.1:6379).
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use EruoFood\PublicApi\Application\Port\QuotaStore;
use EruoFood\PublicApi\Application\Port\RateLimiter;
use EruoFood\PublicApi\Infrastructure\RateLimit\CacheQuotaStore;
use EruoFood\PublicApi\Infrastructure\RateLimit\CacheRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);
config([
    'cache.default' => 'redis',
    'database.redis.default.host' => $host,
    'database.redis.default.port' => $port,
    'database.redis.cache.host' => $host,
    'database.redis.cache.port' => $port,
]);

$pass = 0;
$fail = 0;
function check(string $label, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  — {$label}".($extra !== '' ? "  ({$extra})" : '')."\n";
    } else {
        $fail++;
        echo "  FAIL — {$label}".($extra !== '' ? "  ({$extra})" : '')."\n";
    }
}

// Sanity: we are really talking to Redis.
try {
    Redis::connection()->set('efk:validation:ping', '1');
    $live = Redis::connection()->get('efk:validation:ping') === '1';
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot reach Redis at {$host}:{$port}: {$e->getMessage()}\n");
    exit(2);
}
echo "Connected to real Redis at {$host}:{$port}\n\n";

// A raw phpredis client (no Laravel key-prefix) for probes that must observe the
// exact same key namespace the forked workers write to.
$raw = new \Redis();
$raw->connect($host, $port, 2.0);

$redisCache = Cache::store('redis');

echo "1) Rate limiting (fixed window) against Redis:\n";
$limiter = new CacheRateLimiter($redisCache);
$key = 'efk:test:rl:'.uniqid();
$max = 5;
$allowed = 0;
$blocked = 0;
$lastRemaining = null;
for ($i = 0; $i < 8; $i++) {
    $r = $limiter->hit($key, $max, 60);
    $r->allowed ? $allowed++ : $blocked++;
    $lastRemaining = $r->remaining;
}
check('first 5 requests allowed, next 3 blocked', $allowed === 5 && $blocked === 3, "allowed={$allowed} blocked={$blocked}");
check('remaining floors at 0', $lastRemaining === 0);
check('counter persisted in Redis', $redisCache->get($key.':'.(int) floor(time() / 60)) >= 8);

echo "\n2) Quota store (increment + read) against Redis:\n";
$quota = new CacheQuotaStore($redisCache);
$qkey = 'efk:test:quota:'.uniqid();
for ($i = 0; $i < 10; $i++) {
    $quota->increment($qkey, 3600);
}
check('increment accumulated to 10', $quota->current($qkey) === 10, 'current='.$quota->current($qkey));

echo "\n3) Distributed counter — atomicity under real concurrency (forked processes):\n";
$ckey = 'efk:test:concurrent:'.uniqid();
$raw->del($ckey);
$procs = 20;
$perProc = 100;
$worker = __DIR__.'/redis_concurrency_worker.php';
// Launch all workers in the background and wait for them together — true OS
// parallelism, so the final count only reconciles if INCR is atomic.
$cmds = [];
for ($p = 0; $p < $procs; $p++) {
    $cmds[] = sprintf('php %s %s %s %d %d', escapeshellarg($worker), escapeshellarg($host), escapeshellarg($ckey), $port, $perProc);
}
$script = implode(' & ', $cmds).' & wait';
exec('/bin/bash -c '.escapeshellarg($script), $out, $code);
$total = (int) $raw->get($ckey);
$expected = $procs * $perProc;
check("no lost updates under {$procs} concurrent processes", $total === $expected, "counted={$total} expected={$expected}");

echo "\n4) Cache behaviour (put / get / TTL / forget) against Redis:\n";
$cacheKey = 'efk:test:cache:'.uniqid();
$redisCache->put($cacheKey, 'value-123', 60);
check('value round-trips through Redis', $redisCache->get($cacheKey) === 'value-123');
// Read TTL through the cache's own connection + prefix (its client auto-applies
// the connection prefix, so we add only the store prefix).
$store = $redisCache->getStore();
$client = $store->connection()->client();
$ttl = (int) $client->ttl($store->getPrefix().$cacheKey);
check('TTL is set (~60s)', $ttl > 0 && $ttl <= 60, "ttl={$ttl}");
$redisCache->forget($cacheKey);
check('forget removes the key', $redisCache->get($cacheKey) === null);

echo "\n5) Failure / recovery — window expiry resets the limiter:\n";
$rkey = 'efk:test:rlwin:'.uniqid();
$r1 = $limiter->hit($rkey, 2, 1); // 1-second window
$limiter->hit($rkey, 2, 1);
$blockedNow = ! $limiter->hit($rkey, 2, 1)->allowed;
sleep(2); // let the window roll over
$afterReset = $limiter->hit($rkey, 2, 1)->allowed;
check('limiter blocks within the window then recovers after expiry', $blockedNow && $afterReset);

// Cleanup test keys.
foreach (Redis::connection()->keys('*efk:test:*') as $k) {
    Redis::connection()->del($k);
}
Redis::connection()->del('efk:validation:ping');

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
