# EruoFood AI — Production Readiness (Milestone 18)

This report records what was **actually executed** in a real runtime during
Milestone 18, using only four verdicts:

- **EXECUTED — PASSED**
- **EXECUTED — FAILED**
- **STATIC VALIDATION ONLY**
- **NOT VALIDATED**

## Test environment (reproducible)

| Component | Value |
|---|---|
| PHP | 8.4.19 (cli, NTS) |
| Laravel | 12.64.0 |
| PostgreSQL | 16.13 (local cluster, db `eruofood_test` / `eruofood_fresh`) |
| Redis | 7.0.15 (127.0.0.1:6379) |
| Node | 22.22.2 |
| Composer | 2.x (dependencies installed via git-source through the session proxy) |
| Pest / PHPUnit | pest 3.8.7 / phpunit 12 |
| OS | Linux x86_64 |
| Flutter | **absent** in this environment |
| k6 | **absent** in this environment |

The canonical automated-test database is SQLite `:memory:` (as configured in
`apps/api/phpunit.xml`); PostgreSQL and Redis were additionally exercised
directly (see below).

## Summary of executed validation

| Area | Verdict | Evidence |
|---|---|---|
| Composer install (full, real) | EXECUTED — PASSED | 90 packages; Laravel + Pest + PHPStan present; autoloader boots. |
| Full Pest suite (SQLite, canonical) | EXECUTED — **328 passed / 7 failed** (1295 assertions, ~43s) | See `VALIDATION_STATUS.md`. 8 real defects found and fixed (111 → 7 failures). |
| PostgreSQL migrations from empty DB | EXECUTED — PASSED | 101 migrations; 104 tables, 405 indexes; `migrate:rollback` + re-`migrate` clean. |
| Redis primitives (rate limit / quota / counters / cache / recovery) | EXECUTED — PASSED | `scripts/redis_validation.php` 9/9, incl. **2000/2000** atomic increments across 20 concurrent OS processes. |
| OAuth2 (DB-backed) security | EXECUTED — PASSED | `scripts/oauth_db_validation.php` 18/18 (PKCE, single-use codes, redirect validation, refresh rotation + reuse detection, client isolation, scope escalation, expiry, revocation). |
| OpenAPI contract | EXECUTED — PASSED | `redocly lint` 0 errors (411 style warnings). |
| Docker stack | STATIC VALIDATION ONLY | `docker compose config` merges & validates all 9 services (api, worker, scheduler, nginx, web, postgres, redis, minio, mailpit); images/Dockerfiles present. Full build+boot NOT run here. |
| CI pipeline | STATIC VALIDATION ONLY | Workflows authored (`ci-api` now provisions PostgreSQL + Redis services, runs standards, static analysis, coverage, fresh-migration, Redis validation; plus `ci-web`, `ci-mobile`, `contracts`, `security`). Not executed on GitHub in this session. |
| Load / stress / soak | NOT VALIDATED | No k6 binary and no deployed target here. Script provided: `load/public-api.k6.js`. |
| Performance baseline (p50/p95/p99, RPS) | NOT VALIDATED | Requires the load run above. See `PERFORMANCE_REPORT.md`. |
| Flutter analyze / test | NOT VALIDATED | Flutter SDK absent. Static structure only (prior milestones). |

## Defects discovered and fixed (by the executed suite)

1. **Catalog foods migration** created a duplicate index (`category_id`) — fails a fresh migration on SQLite and PostgreSQL. Removed the redundant index.
2. **Identity social auth** bound `make('http')` (no such binding) → resolved `Illuminate\Http\Client\Factory`.
3. **Notifications DI** — default `QuietHours` used a name-based contextual binding for a class-typed parameter (never consulted) → switched to type-based.
4. **Commerce / Payments controllers** with a `string $currency` dependency were missing from the currency contextual-binding lists → added.
5. **Commerce `ProductService::ownedStore()`** was `: void` but its return value was used → now returns the `Store`.
6. **AI `ConversationMessageModel`** wrote a non-existent `updated_at` column → `const UPDATED_AT = null`.
7. **Public API auth middleware** only tried the first presented credential, so a stale `Authorization` bearer shadowed an explicit `X-Api-Key` → now tries `X-Api-Key` first, then the bearer.
8. **Search documents migration** — best-effort pgvector/pg_trgm setup aborted the migration transaction on PostgreSQL when the extension is unavailable, rolling back the table → `$withinTransaction = false`.

## Remaining EXECUTED — FAILED (7, on the SQLite canonical run)

| Test | Nature | Notes |
|---|---|---|
| Nutrition `MealPlanFlow` — estimated_cost | SQLite numeric coercion | Asserts `1400.0` (float); SQLite returns whole reals as int `1400`. Passes on a real decimal column. |
| Reviews `ReviewsApi` — average | SQLite numeric coercion | Asserts `5.0`; SQLite returns `5`. |
| Admin `AdminFlow` — audit order | Nondeterministic ordering | `data.0.action` depends on `created_at` ordering when entries share a timestamp; needs a deterministic tiebreaker (id/sequence). |
| Notifications `NotificationCentre` — channel preference | Feature logic | A category restricted to email-only still produced an in-app notification; preference round-trip needs investigation. |
| Analytics `AnalyticsFlow` — revenue KPI (×2) | Feature logic | Executive dashboard revenue aggregated to 0; event→metric projection not reflected synchronously in this flow. |
| Search `SearchDiscovery` — unpublish removal | Feature logic | Re-emitting the publish event after unpublishing at source did not drop the index entry. |

None of these are in the Public API / auth / Redis / OAuth paths that were the GA
focus; they are pre-existing cross-module feature edge cases surfaced for the
first time by the now-executable suite. They are tracked as remaining debt.

> A separate PostgreSQL run of the suite showed additional failures caused by
> feature-test **fixtures** using non-UUID identifiers (e.g. `u1`, `system`) in
> columns PostgreSQL strictly types as `uuid`. Production identifiers are real
> UUIDs, so these are test-data limitations rather than production defects; they
> are noted for a future test-fixture cleanup.

## GO / NO-GO

**Verdict: NO-GO for full-platform production; GO-ready for the Public API surface under stated conditions.**

The Public API, authentication (API keys + OAuth2), object-level authorization
(BOLA), webhook SSRF defence, Redis-backed rate limiting/quotas/counters, and
the PostgreSQL schema are all **EXECUTED — PASSED**. What must clear before a
full-platform production GO:

1. Resolve the 7 remaining feature-test failures (or formally accept the numeric
   ones as SQLite-only and fix the 3 genuine logic ones: Notifications channel
   preference, Analytics revenue KPI, Search unpublish removal).
2. Run `load/public-api.k6.js` against a staging deployment and record the
   performance baseline (p50/p95/p99, RPS, error rate) in `PERFORMANCE_REPORT.md`.
3. Execute the CI pipeline on GitHub (services now provisioned) and confirm green.
4. Build and boot the full Docker stack in staging (compose config already valid).
5. Run `flutter analyze` + `flutter test` in an environment with the Flutter SDK.
6. Apply the infrastructure egress controls in `WEBHOOKS.md` and run the external
   penetration-test checklist in `SECURITY_AUDIT.md`.
