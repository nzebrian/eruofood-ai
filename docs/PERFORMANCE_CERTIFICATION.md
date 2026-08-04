# EruoFood AI — Performance Certification

How to run the k6 performance certification against a deployed staging target and
what constitutes a pass. This is the executable form of the "performance
certification on scaled staging" blocker.

## Prerequisites
- Staging deployed and healthy (`docs/STAGING_DEPLOYMENT.md`).
- `staging` Environment secrets: `STAGING_BASE_URL`, `STAGING_API_KEY`
  (+ optional `STAGING_OAUTH_CLIENT_ID`/`SECRET`).

## Run
`Actions → Performance Certification (k6 · staging) → Run workflow`. The matrix
runs both scripts across the scenarios:

| Script | Flows covered |
|---|---|
| `load/public-api.k6.js` | status, foods, **recipes**, **restaurants**, **products**, nutrition, **search**; rate-limit/quota (429) |
| `load/critical-flows.k6.js` | **authentication** (register/login/refresh), **OAuth2** token, **orders** lifecycle, rate limiting/quotas |

Scenarios: **baseline · load · stress · spike** (matrix), plus **soak** (2 h,
opt-in via the `soak` input).

## Pass/fail thresholds (enforced by k6 — a breach fails the job)
| Metric | Threshold |
|---|---|
| `http_req_duration` p95 | `< 400 ms` |
| `http_req_duration` p99 | `< 800 ms` |
| application error rate (`flow_errors` / `http_req_failed`) | `< 1%` |
| `auth_latency` p95 (critical flows) | `< 500 ms` |
| `order_latency` p95 (critical flows) | `< 600 ms` |

k6 exits non-zero when any threshold is breached, so the GitHub job goes red — the
job status **is** the pass/fail signal. No number is manufactured.

## Reports
- **Machine-readable:** `--summary-export` JSON + raw `--out json` per
  script/scenario, uploaded as `perf-*` artifacts.
- **Human-readable:** each job writes a p50/p95/p99/req-per-sec/error-rate table
  into the GitHub job **Summary**.

## Recording results
After a green run, transcribe the p50/p95/p99, req/s, and error rate for each
scenario into the "Fill in after the staging run" table in
`docs/PERFORMANCE_REPORT.md`, and flip the performance line there and in
`VALIDATION_STATUS.md` from **NOT VALIDATED** to **EXECUTED — PASSED** with the
run link.

## Target environment (for representative numbers)
- API/worker pods sized to production (php-fpm pool tuned, opcache on).
- Managed PostgreSQL 16 + Redis 7, **not** co-located with the app under test.
- Seeded dataset at production cardinality.
- k6 (the GitHub runner) on a separate host/region from the target.

## Status
**READY TO EXECUTE.** The production performance baseline remains **NOT
VALIDATED** until this workflow runs green against staging and the numbers are
recorded. The functional latency floor measured in the sandbox
(`scripts/perf_probe.php`: p50 26.5 / p95 31.9 / p99 35.1 ms) is a floor, not a
capacity certification.
