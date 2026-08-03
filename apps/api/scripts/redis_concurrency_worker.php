<?php

declare(strict_types=1);

/**
 * Concurrency worker for redis_validation.php. Each forked process opens its own
 * Redis connection and INCRs the shared key N times. If Redis INCR is atomic
 * (it is), the sum across all workers equals processes × N with no lost updates.
 *
 * Args: <host> <key> <port> <iterations>
 */

$host = $argv[1] ?? '127.0.0.1';
$key = $argv[2] ?? 'efk:test:concurrent';
$port = (int) ($argv[3] ?? 6379);
$iterations = (int) ($argv[4] ?? 100);

$redis = new Redis();
$redis->connect($host, $port, 2.0);
for ($i = 0; $i < $iterations; $i++) {
    $redis->incr($key);
}
$redis->close();
