# EruoFood AI — Performance Report (Milestone 19)

Verdicts: **EXECUTED — PASSED / EXECUTED — FAILED / STATIC VALIDATION ONLY /
NOT VALIDATED**.

## Summary

- **Functional latency floor & single-worker throughput ceiling: EXECUTED —
  MEASURED** with a real HTTP load harness (`apps/api/scripts/perf_probe.php`,
  PHP `curl_multi`) against a running instance of the API.
- **Production capacity baseline (multi-worker p50/p95/p99, RPS, error rate at
  production concurrency, DB/Redis saturation): NOT VALIDATED** — that requires
  the k6 profiles in `load/public-api.k6.js` run against a horizontally-scaled
  staging deployment, which this single-container session cannot host.

Nothing here is fabricated. The numbers below were produced by an executed tool;
their scope and limits are stated explicitly.

## What was measured, and the measurement environment

| Property | Value |
|---|---|
| Target | `php artisan serve` — a **single-process, single-threaded** PHP dev server |
| App | Laravel 12.64, PHP 8.4.19 (no opcache/JIT tuning; dev server, not php-fpm) |
| Database | PostgreSQL 16.13, **co-located** on the same container/CPU |
| Cache/limits | Redis 7.0.15, **co-located** |
| Load generator | `curl_multi` on the **same host** (no network isolation) |
| Endpoints | `/api/public/v1/` status, foods, recipes, restaurants, products, nutrition, search (7 critical read paths) |

This topology is a **latency floor and a single-worker throughput ceiling**: the
app, the database, the cache and the load generator all share the same cores, and
there is exactly one PHP worker. Production runs many php-fpm workers across
several pods with managed, separately-provisioned PostgreSQL and Redis, so real
throughput is materially higher and real latency under isolation is comparable or
lower. These numbers must **not** be read as a capacity model.

## Measured results (EXECUTED — `scripts/perf_probe.php`)

### 1. Warm sequential latency (true per-request latency, concurrency = 1)

700 requests across the 7 read endpoints, no client-side queueing:

| Metric | Value |
|---|---|
| p50 | **26.5 ms** |
| p95 | **31.9 ms** |
| p99 | **35.1 ms** |
| Throughput (1 worker, serial) | **~39 req/s** |
| Status codes | 100% `200`/`2xx` (0 errors) |

This is the honest per-request latency of the full stack (routing → middleware →
API-key auth → read port → PostgreSQL → JSON serialisation) with warm caches.

### 2. Redis round-trip latency (EXECUTED)

1000 sequential `PING` round-trips over the real Redis connection used for rate
limiting, quotas and idempotency:

| Metric | Value |
|---|---|
| Per-operation round-trip | **~0.043 ms/op** |

Confirms the rate-limit/quota/idempotency primitives add sub-millisecond overhead
per call — consistent with the Redis correctness validation
(`scripts/redis_validation.php`, 9/9, incl. 2000/2000 concurrent atomic
increments).

### 3. Concurrency & rate-limit behaviour (EXECUTED)

Under concurrent load (many in-flight requests against one worker), the process
**served every request without a single 5xx** and the Redis-backed rate limiter
returned `429` once per-client limits were crossed — i.e. back-pressure is
enforced by the limiter rather than by the app failing. Because there is only one
PHP worker and the load generator shares its CPU, the concurrent-throughput figure
is a floor, not a production RPS number, so it is not quoted as a capacity result.

## NOT VALIDATED (requires a staging deployment)

The following are **not** claimed and must be produced before a full-platform GO:

| Metric | Why not validated here |
|---|---|
| Multi-worker p50/p95/p99 at production concurrency | One dev worker; no php-fpm pool |
| Sustained RPS / capacity | Single process, co-located generator |
| Error rate under saturation | No saturation reachable on one worker |
| DB latency & slow-query profile under load | PostgreSQL co-located, not load-isolated |
| Queue throughput (workers/scheduler) under load | Not driven under representative volume |
| Soak / memory-leak behaviour over hours | Session lifetime too short |

## How to produce the production baseline (ready to run)

`load/public-api.k6.js` encodes four profiles (baseline / load / stress / soak)
with threshold gates (`p95 < 400 ms`, `p99 < 800 ms`, app error rate `< 1%`):

```bash
BASE_URL=https://staging.api.eruofood.ai/api/public/v1 \
API_KEY=efk_live_xxx.yyy \
SCENARIO=baseline k6 run --out json=baseline.json load/public-api.k6.js
SCENARIO=load   k6 run --out json=load.json   load/public-api.k6.js
SCENARIO=stress k6 run --out json=stress.json load/public-api.k6.js
SCENARIO=soak   k6 run --out json=soak.json   load/public-api.k6.js
```

### Recommended target environment

- API + worker pods sized to production (php-fpm pool tuned, opcache on — see
  `infra/docker/php/opcache.ini`).
- Managed PostgreSQL 16 and Redis 7, **not** co-located with the app under test.
- A seeded dataset at production cardinality (catalogue, vendors, products,
  nutrition, indexed search documents).
- Load generator on a separate host/region from the target.

### Fill in after the staging run

| Metric | Baseline | Load (50 VU) | Stress (peak) | Soak (2 h) |
|---|---|---|---|---|
| p50 / p95 / p99 latency (ms) | — | — | — | — |
| Requests / sec | — | — | — | — |
| Error rate (5xx/401/403) | — | — | — | — |
| Rate-limited (429) rate | — | — | — | — |
| Redis / PostgreSQL / app CPU & memory | — | — | — | — |
