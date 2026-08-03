<?php

declare(strict_types=1);

/**
 * Milestone 19 — real performance probe against a running instance.
 *
 * Drives the public API's critical read paths and measures actual latency
 * (p50/p95/p99), throughput and error rate with PHP's curl_multi. It is NOT a
 * substitute for a distributed k6 run against production hardware — the target
 * here is a single-process `php artisan serve` dev server sharing this
 * container's CPU with PostgreSQL and Redis, so the numbers are a functional
 * latency floor and a single-worker throughput ceiling, not a capacity model.
 * The k6 script in load/public-api.k6.js remains the tool for a production run.
 *
 * Usage: BASE=http://127.0.0.1:8099 APIKEY=... php scripts/perf_probe.php
 */

$base = getenv('BASE') ?: 'http://127.0.0.1:8099';
$apiKey = getenv('APIKEY') ?: trim((string) @file_get_contents('/tmp/perf_apikey.txt'));

$paths = [
    '/api/public/v1/status'            => false, // unauth
    '/api/public/v1/foods?per_page=20' => true,
    '/api/public/v1/recipes?per_page=20' => true,
    '/api/public/v1/restaurants?per_page=20' => true,
    '/api/public/v1/products?per_page=20' => true,
    '/api/public/v1/nutrition?per_page=20' => true,
    '/api/public/v1/search?q=rice&type=recipe' => true,
];

function pct(array $sorted, float $p): float
{
    if ($sorted === []) {
        return 0.0;
    }
    $idx = (int) ceil($p / 100 * count($sorted)) - 1;

    return $sorted[max(0, min($idx, count($sorted) - 1))];
}

/**
 * Run $total requests with $concurrency in flight; return latency samples (ms)
 * and status-code counts.
 *
 * @param list<array{0:string,1:bool}> $reqs
 * @return array{lat: list<float>, codes: array<int,int>, wall: float}
 */
function runLoad(string $base, string $apiKey, array $reqs, int $total, int $concurrency): array
{
    $mh = curl_multi_init();
    $latencies = [];
    $codes = [];
    $inflight = [];
    $started = [];
    $done = 0;
    $queued = 0;

    $spawn = function () use (&$queued, &$inflight, &$started, $reqs, $base, $apiKey, $mh, $total): bool {
        if ($queued >= $total) {
            return false;
        }
        [$path, $auth] = $reqs[$queued % count($reqs)];
        $ch = curl_init($base.$path);
        $headers = ['Accept: application/json'];
        if ($auth) {
            $headers[] = 'X-Api-Key: '.$apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);
        $id = (int) $ch;
        $started[$id] = microtime(true);
        $inflight[$id] = $ch;
        curl_multi_add_handle($mh, $ch);
        $queued++;

        return true;
    };

    $wall = microtime(true);
    for ($i = 0; $i < $concurrency; $i++) {
        $spawn();
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.5);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = (int) $ch;
            $latencies[] = (microtime(true) - $started[$id]) * 1000;
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $codes[$code] = ($codes[$code] ?? 0) + 1;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($inflight[$id]);
            $done++;
            $spawn();
        }
    } while ($done < $total);
    $wall = microtime(true) - $wall;
    curl_multi_close($mh);

    return ['lat' => $latencies, 'codes' => $codes, 'wall' => $wall];
}

echo "Target: {$base}  (single-process php artisan serve; PG + Redis co-located)\n\n";

// --- 1) Sequential warm latency (true per-request latency, no queueing) ---
$reqs = [];
foreach ($paths as $p => $auth) {
    $reqs[] = [$p, $auth];
}
echo "[1] Warm sequential latency — 700 requests across ".count($reqs)." endpoints, concurrency 1\n";
$seq = runLoad($base, $apiKey, $reqs, 700, 1);
sort($seq['lat']);
printf(
    "    p50=%.1fms  p95=%.1fms  p99=%.1fms  max=%.1fms  RPS=%.0f\n",
    pct($seq['lat'], 50),
    pct($seq['lat'], 95),
    pct($seq['lat'], 99),
    end($seq['lat']),
    700 / $seq['wall'],
);
printf("    status codes: %s\n\n", json_encode($seq['codes']));

// --- 2) Concurrent throughput (single-worker ceiling + rate-limit behaviour) ---
echo "[2] Concurrent load — 1500 requests, concurrency 50\n";
$con = runLoad($base, $apiKey, $reqs, 1500, 50);
sort($con['lat']);
$err = 0;
foreach ($con['codes'] as $c => $n) {
    if ($c >= 500 || $c === 0 || $c === 401 || $c === 403) {
        $err += $n;
    }
}
printf(
    "    p50=%.1fms  p95=%.1fms  p99=%.1fms  max=%.1fms  RPS=%.0f\n",
    pct($con['lat'], 50),
    pct($con['lat'], 95),
    pct($con['lat'], 99),
    end($con['lat']),
    1500 / $con['wall'],
);
printf("    status codes: %s\n", json_encode($con['codes']));
printf(
    "    error rate (5xx/401/403/conn): %.2f%%   rate-limited (429): %d\n\n",
    $err / 1500 * 100,
    $con['codes'][429] ?? 0,
);

// --- 3) Redis + DB latency snapshot ---
$r = microtime(true);
$rc = new \Redis();
$rc->connect('127.0.0.1', 6379);
for ($i = 0; $i < 1000; $i++) {
    $rc->ping();
}
printf("[3] Redis: 1000 PING round-trips in %.1fms (%.3fms/op)\n", (microtime(true) - $r) * 1000, (microtime(true) - $r));
$rc->close();

echo "\nDone.\n";
