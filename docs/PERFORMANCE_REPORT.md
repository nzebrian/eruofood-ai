# EruoFood AI — Performance Report (Milestone 18)

## Status: NOT VALIDATED (no load-test environment in this session)

Honest classification: performance was **NOT VALIDATED**. This session had no
`k6` binary and no deployed, seeded target to drive representative load against.
No latency, throughput, or error-rate numbers are reported here — fabricating
them would violate the milestone's honesty requirement.

What **was** measured (single-request, functional, not a performance baseline):

- Full Pest suite wall time: ~43 s for 335 tests on SQLite `:memory:`.
- PostgreSQL migration of the full schema (101 migrations): well under a second
  per migration (5–90 ms each, observed in `artisan migrate` output).
- Redis primitives under 20 concurrent OS processes performing 2000 increments:
  completed within the validation script's runtime with zero lost updates.

These confirm correctness under light concurrency; they are **not** a load or
latency baseline.

## How to produce the baseline (ready to run)

A k6 script is provided at `load/public-api.k6.js` with four profiles and
threshold gates. Run it against staging (not a dev server):

```bash
BASE_URL=https://staging.api.eruofood.ai/api/public/v1 \
API_KEY=efk_live_xxx.yyy \
SCENARIO=baseline k6 run --out json=baseline.json load/public-api.k6.js

SCENARIO=load   k6 run --out json=load.json   load/public-api.k6.js
SCENARIO=stress k6 run --out json=stress.json load/public-api.k6.js
SCENARIO=soak   k6 run --out json=soak.json   load/public-api.k6.js
```

Critical paths exercised: foods, recipes, restaurants, products, nutrition,
search, plus rate-limit / quota (429) behaviour under stress.

## Metrics to record (fill in after the run)

| Metric | Baseline | Load (50 VU) | Stress (peak) | Soak (2 h) |
|---|---|---|---|---|
| p50 latency (ms) | — | — | — | — |
| p95 latency (ms) | — | — | — | — |
| p99 latency (ms) | — | — | — | — |
| Requests / sec | — | — | — | — |
| Error rate (5xx/401/403) | — | — | — | — |
| Rate-limited (429) rate | — | — | — | — |
| Redis CPU / memory | — | — | — | — |
| PostgreSQL CPU / slow queries | — | — | — | — |
| App CPU / memory (per pod) | — | — | — | — |

Thresholds already encoded as gates in the k6 script: `p95 < 400 ms`,
`p99 < 800 ms`, application error rate `< 1%`.

## Recommended target environment

- API + worker pods sized to production (php-fpm pool tuned, opcache on — see
  `infra/docker/php/opcache.ini`).
- Managed PostgreSQL 16 and Redis 7 (not co-located with the app under test).
- A seeded dataset representative of production cardinality (catalogue, vendors,
  products, nutrition, indexed search documents).
- Load generator on a separate host/region from the target.
