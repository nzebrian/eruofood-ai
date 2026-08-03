# Performance & Load Testing

Executable k6 suite for the EruoFood AI performance certification. Run against a
**production-equivalent staging** deployment — never a dev server, never
production.

## Scripts

| Script | Covers |
|---|---|
| `public-api.k6.js` | Public API read paths: status, foods, recipes, restaurants, products, nutrition, search; rate-limit/quota (429) behaviour |
| `critical-flows.k6.js` | Internal auth (register/login/refresh), OAuth2 token, order lifecycle, rate limiting |

Together these exercise every flow the certification requires: authentication,
Public API, recipes, search, restaurants, products, orders, OAuth2, Redis rate
limiting, and quotas.

## Profiles (SCENARIO env)

`baseline` · `load` (50 VU) · `stress` (ramp to 300) · `spike` (burst to 400) ·
`soak` (2 h). Thresholds are encoded as gates: `p95 < 400ms`, `p99 < 800ms`,
error rate `< 1%`.

## Run

```bash
export BASE_URL=https://staging.api.eruofood.ai
export API_KEY=efk_live_xxx.yyy
export OAUTH_CLIENT_ID=... OAUTH_CLIENT_SECRET=...   # optional, for the OAuth flow
bash load/run.sh          # full matrix → load/results/<ts>/
# or a single run:
SCENARIO=load k6 run load/critical-flows.k6.js
```

## Metrics to record (into docs/PERFORMANCE_REPORT.md)

p50 / p95 / p99 latency, requests/sec, error rate, 429 rate, and — from the
platform's own monitoring during the run — CPU, memory, PostgreSQL latency &
slow queries, Redis latency, and queue throughput.

## Status

The production capacity baseline is **NOT VALIDATED** until this suite is run on
staging with real infrastructure and the numbers are transcribed. See
`docs/PERFORMANCE_REPORT.md` for what has (and has not) been measured.
