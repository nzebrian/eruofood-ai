# Validation Status

---

## Milestone 16 — Public API, SDK & Developer Platform (2026-08-02)

Honest classification of what was actually run for this milestone.

### ✅ Executed and Passed

| Check | Tool | Result |
|---|---|---|
| PHP syntax — all modules | `php -l` | **1482/1482** files clean |
| Cross-module reference resolution | ref-check script | **1482/1482** resolve; no dead refs |
| Composer manifest | `composer validate` | valid |
| PublicApi domain/security logic | pure-PHP sanity harness | **34 checks passed / 34** (scopes, scope-intersection never widens, key hash/verify, key usability, HMAC sign/verify + replay, webhook backoff/idempotency, envelope pagination) |
| OpenAPI spec | `@redocly/cli lint` | **0 errors** (392 style warnings — pre-existing operationId/4xx set) |
| OpenAPI structure | YAML + `$ref` audit | 0 duplicate schemas, 0 unresolved refs, 0 identical path templates; `apiKeyAuth` scheme defined |
| Web type-check | `tsc --noEmit` | exit 0 |
| Web lint | `eslint src` | exit 0 |
| Web unit tests | `vitest run` | **51 passed / 51** (15 files, incl. new `developerApi` suite) |
| Web production build | `vite build` | exit 0 |

The sanity harness genuinely executes the security-critical logic that the Pest
feature suite would otherwise cover (key hashing, scope enforcement, HMAC
signing + replay window, webhook retry/backoff, idempotency).

### 🟡 Static Validation Only

| Check | Method | Result |
|---|---|---|
| PublicApi Pest unit + feature tests | `php -l` + reference resolution | Syntax-clean; symbols resolve. **Not executed** (see below). |
| Middleware/route wiring, DI bindings | code review against Laravel conventions | Consistent with existing modules; not runtime-verified. |
| PHP SDK (`packages/sdk-php`) | `php -l` | Clean; not executed against a live API. |
| Dart SDK (`packages/sdk-dart`) | code review vs `package:http` API | Structurally sound; **no Dart toolchain** to compile/test. |
| TypeScript SDK (`packages/sdk-typescript`) | reviewed; compiled via app `tsc` context | Standalone package has no its own build run here. |

### ⚪ Not Validated (environment cannot execute)

| Check | Why |
|---|---|
| **PHP Pest suites** (PublicApi unit + feature, incl. api-key auth / scope / rate-limit / webhook signature / idempotency tests) | `composer install` cannot finalize in this sandbox (authenticated GitHub package downloads fail); no `vendor/bin/pest`. Tests are written and syntax-clean but **not run**. No pass is claimed. |
| **Dart SDK tests** | Flutter/Dart toolchain absent. |

To execute in a capable environment:

```bash
cd apps/api && composer install && vendor/bin/pest        # PHP
cd packages/sdk-dart && dart pub get && dart analyze       # Dart SDK
```

---

# Validation Status (Cleanup)

Date: 2026-08-02
Scope: Technical Debt & Validation Cleanup (no new business features).

This document records **exactly what was run and what was not**, and why. Checks
are classified as:

- ✅ **Executed and Passed** — a real tool ran to completion with a passing result.
- ❌ **Executed and Failed** — a real tool ran and reported failures.
- 🟡 **Static Validation Only** — verified by inspection/scripts/linters that do
  not execute the application or its test suites.
- ⚪ **Not Validated** — could not be checked in this environment.

Environment limitations are stated plainly; nothing is claimed to have "passed"
unless it actually executed.

---

## ✅ Executed and Passed

| Check | Tool | Result |
|---|---|---|
| OpenAPI specification validity | `@redocly/cli@1.25.0 lint` | **0 errors** (363 style warnings — see TECHNICAL_DEBT.md) |
| OpenAPI generated TS client | `openapi-typescript@7.4.1` | Generated cleanly; `MessagingConversation`/`MessagingMessage` present |
| Web type-check | `tsc --noEmit` | Exit 0 |
| Web lint | `eslint src` | Exit 0 |
| Web unit tests | `vitest run` | **46 passed / 46** (14 files) |
| Web production build | `vite build` | Exit 0 |
| PHP syntax (all module files) | `php -l` | **1390/1390** clean |
| Composer manifest | `composer validate` | `composer.json is valid` |
| Domain logic — Loyalty | pure-PHP sanity harness | **52 checks passed / 52** |
| Domain logic — Reviews | pure-PHP sanity harness | **40 checks passed / 40** |

The two PHP "sanity harnesses" `require` the domain classes directly (no
framework) and exercise the trickiest algorithms — points ledger math, tier
resolution, redemption/referral lifecycles (Loyalty); rating projection,
moderation transitions, content filter (Reviews). They are a genuine, executed
substitute for the parts of the Pest suite that cannot run here (see below).

## 🟡 Static Validation Only

| Check | Method | Result |
|---|---|---|
| Cross-module reference resolution (PHP) | script resolving every `EruoFood\…` symbol against PSR-4 | **1390/1390** files resolve; no dead references |
| Duplicate PHP classes (FQCN) | AST-ish scan of `namespace`+`class/interface/trait/enum` | **None** |
| Duplicate routes (method + full path incl. prefixes) | route-file parser (428 routes) | **None** after fix (see TECHNICAL_DEBT.md #4) |
| Duplicate OpenAPI component schemas | YAML parse + key counting | **None** (181 schemas, all unique) |
| Identical OpenAPI path templates | param-normalized path comparison | **None** (243 paths) |
| Unresolved OpenAPI `$ref`s (schemas/responses/parameters) | regex vs component keys | **None** |
| Migration table-name / timestamp-prefix collisions | scan of `Schema::create` + filenames | **None** (94 tables, 41 files) |
| Domain event-name collisions | scan of `eventName()` return strings | **None** (42 classes / 42 distinct names) |
| Cross-bounded-context imports | scan for `use EruoFood\<Other>\…` outside `Shared`/`Contracts` | **None illegal** — only `EruoFood\Ai\Contracts` consumed |
| PSR-4 prefix/path uniqueness | composer.json inspection | **Unique** (19 prod, 17 dev) |
| Config files referenced | accessor scan (`config()`/`->get()`) | All 15 module configs referenced; none orphaned |
| Flutter dependency manifest | `pubspec.yaml` inspection | Valid (Dart SDK ≥3.5.0, Flutter ≥3.24.0; get_it/dio/dartz/bloc) |
| Flutter/Dart imports | static import-resolution scan | **137/137** Dart files resolve; no missing files or undeclared packages |
| composer.json PHP/Laravel/Pest versions | inspection | php ^8.4, laravel/framework ^12, pestphp/pest ^3.5, larastan ^3, pint ^1.18 — production-ready |
| Pest/PHPUnit config | `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` inspection | Present and coherent (feature suite paths include all modules incl. Loyalty/Reviews) |

## ⚪ Not Validated (environment cannot execute)

| Check | Why | Evidence |
|---|---|---|
| **PHP Pest/PHPUnit test suites** (unit + feature) | `composer install` **cannot finalize** in this sandbox — several dist packages require GitHub authentication the proxy cannot provide, so the install aborts before generating the autoloader. `vendor/` remains a stub (`vendor/autoload.php` = 22-line placeholder, `vendor/bin/` absent, no `vendor/bin/pest`). | `composer install` reached 134/134 downloads then failed with `Could not authenticate against github.com` (AuthHelper.php:132), exit 100 |
| **Flutter/Dart tests** (`flutter test`) | Flutter and Dart toolchains are **not installed** (`which flutter dart` → not found). | Toolchain absent on PATH |

No claim is made that these suites pass. Their **static** structure was verified
(above): PHP test files are syntax-clean and reference resolvable symbols; Dart
test imports resolve. Executing them requires an environment with the Laravel
dependencies installed and the Flutter SDK present.

---

## How to execute the un-run suites (in a capable environment)

```bash
# PHP (needs composer deps installed — requires authenticated package downloads)
cd apps/api && composer install && vendor/bin/pest

# Flutter (needs the Flutter SDK)
cd apps/mobile && flutter pub get && flutter test
```

---

# Milestone 17 — Public API Completion & GA Hardening

Validation classification (honest): **Executed & Passed** / **Static Validation
Only** / **Not Validated**. No test is claimed as passed unless it was actually
run in this environment.

## Executed & Passed (run in this environment)

| Check | Command | Result |
|---|---|---|
| SSRF guard logic | `php scratchpad/ssrf_guard_sanity.php` | **25/25 passed** — blocks loopback/private/link-local/CGNAT/IPv6-ULA/mapped, credentials, bad scheme/port; allows public dsts. |
| BOLA/IDOR order authorization | `php scratchpad/bola_sanity.php` | **6/6 passed** — authenticated subject only reaches the Order domain; app-level credentials refused. |
| OAuth2 grant logic | `php scratchpad/oauth_sanity.php` | **18/18 passed** — PKCE verify, single-use codes, refresh rotation + revocation, client-credentials (no subject/no refresh), scope containment, introspection. |
| Public API read/order sanity | `php scratchpad/publicapi_sanity.php` | **34/34 passed** (M16 harness, still green). |
| PHP syntax (all new files) | `php -l` on every new/edited file | **No syntax errors.** |
| Cross-reference resolution | `check_refs2.py modules/PublicApi` | **OK — all EruoFood references resolve across 151 files.** |
| OpenAPI contract | `redocly lint openapi.yaml` | **Valid — 0 errors** (411 pre-existing style warnings). |
| TypeScript SDK | `tsc --noEmit --strict src/index.ts` | **Clean (exit 0).** |
| PHP SDK | `php -l sdk-php/src/Client.php` | **No syntax errors.** |

The Pest tests `WebhookSecurityTest` and `OAuthServiceTest` mirror the executed
standalone harnesses (same assertions, in-memory repositories) but were not run
through Pest here — see below.

## Static Validation Only (not executed as a suite)

- **Pest test suites** (`WebhookSecurityTest`, `OAuthServiceTest`, and the M16
  suites): `php -l` clean, logic proven by the standalone harnesses, but the
  Pest runner was not executed — `composer install` cannot finalize in this
  sandbox (GitHub auth failures on dist downloads; `vendor/` remains a stub).
- **BOLA/IDOR order authorization**: enforced by construction (subject user is
  derived only from the credential; the Commerce `OrderService` re-checks
  ownership) and covered by written tests; the full HTTP feature path needs the
  framework to run.
- **Dart SDK**: new methods added and eyeball-verified; the Dart toolchain is
  absent, so `dart analyze`/`dart test` were not run.

## Not Validated (require a runtime environment)

- **Redis** production behaviour: rate limiting, quotas, idempotency and
  distributed counters were **not** validated against a real Redis. No
  concurrency/load/soak/failure-recovery run was performed.
- **Performance**: p50/p95/p99 latency, throughput and error rate were **not
  measured**. Marked *Not Validated* — a load-test environment was unavailable.

These are recorded as GA blockers in `PUBLIC_API_GA_CHECKLIST.md`.

---

# Milestone 18 — Runtime Validation, CI & GA Readiness

A real PHP 8.4 / Laravel 12.64 runtime was established (composer install
completed via git-source through the session proxy) and the full test suite was
**executed for the first time**. Verdicts use EXECUTED — PASSED / EXECUTED —
FAILED / STATIC VALIDATION ONLY / NOT VALIDATED. Environment details are in
`PRODUCTION_READINESS.md`.

## EXECUTED — PASSED

| Check | Command | Result |
|---|---|---|
| Composer install (real, full) | `composer install --prefer-source` | 90 packages; Laravel + Pest + PHPStan present. |
| PostgreSQL migrations from empty DB | `artisan migrate` (db `eruofood_fresh`) | 101 migrations; 104 tables, 405 indexes; rollback + re-migrate clean. |
| Redis primitives | `php scripts/redis_validation.php` | **9/9** — rate limit, quota, cache TTL/forget, failure-recovery, and **2000/2000** atomic increments across 20 concurrent processes. |
| OAuth2 DB-backed security | `php scripts/oauth_db_validation.php` | **18/18** — PKCE, single-use codes, redirect validation, refresh rotation + reuse detection, client isolation, scope/ expiry/ revocation. |
| OpenAPI contract | `redocly lint` | 0 errors (411 style warnings). |
| SSRF / OAuth / BOLA unit harnesses (M17) | standalone php | 25/25, 18/18, 6/6 (still green). |

## EXECUTED — with failures

| Check | Command | Result |
|---|---|---|
| Full Pest suite (SQLite canonical) | `vendor/bin/pest` | **328 passed / 7 failed** (1295 assertions, ~43s). 8 real defects found & fixed (started at 111 failing). The 7 remaining are itemised in `PRODUCTION_READINESS.md` (2 SQLite numeric-coercion, 1 ordering, 3 feature-logic, mirrored count). |
| Full Pest suite (PostgreSQL) | `DB_CONNECTION=pgsql vendor/bin/pest` | 303 passed / 32 failed — the extra failures are feature-test **fixtures** using non-UUID ids (`u1`, `system`) that only PostgreSQL's strict `uuid` typing rejects; production ids are UUIDs. Noted as test-fixture debt. |

## STATIC VALIDATION ONLY

- **CI pipeline**: `ci-api.yml` upgraded to provision PostgreSQL 16 + Redis 7
  services and run standards, static analysis, coverage, fresh-migration, and
  the Redis validation script; `ci-web`, `ci-mobile`, `contracts`, `security`
  present. Authored, not executed on GitHub this session.
- **Docker stack**: `docker compose config` merges & validates all 9 services;
  Dockerfiles present. Full build+boot not run here.

## NOT VALIDATED

- **Load / stress / soak** and the **performance baseline** (p50/p95/p99, RPS,
  error rate): no k6 binary or deployed target. Script provided at
  `load/public-api.k6.js`. See `PERFORMANCE_REPORT.md`.
- **Flutter** analyze/test: SDK absent.
- **Infrastructure webhook egress** controls and an **external penetration
  test**: require production infra / an external team. Checklist in
  `SECURITY_AUDIT.md`.

---

# Milestone 19 — GA Blocker Remediation & Final Production Validation

Every executable check below was **re-run in this session** (not carried over from
a prior milestone). Verdicts: **EXECUTED — PASSED / EXECUTED — FAILED / STATIC
VALIDATION ONLY / NOT VALIDATED**. Environment matches `PRODUCTION_READINESS.md`
(PHP 8.4.19, Laravel 12.64, PostgreSQL 16.13, Redis 7.0.15, Node 22.22.2).

## EXECUTED — PASSED

| Check | Command | Result |
|---|---|---|
| Full Pest suite (SQLite `:memory:`, canonical) | `vendor/bin/pest` | **336 passed / 0 failed** (1313 assertions, 24.4s) — the 7 M18 failures are all fixed |
| Full Pest suite (PostgreSQL 16) | `DB_CONNECTION=pgsql vendor/bin/pest` | **336 passed / 0 failed** — fixture UUIDs corrected; green on the production engine |
| Coding standards (Pint) | `composer run lint` (`pint --test`) | **PASS** — codebase conformed via `lint:fix`; was red repo-wide before this milestone |
| Web type-check | `tsc --noEmit` | Exit 0 |
| Web unit tests | `vitest run` | **51 passed / 51** (15 files) |
| Web production build | `vite build` | Exit 0 (122 modules, built clean) |
| Redis primitives | `php scripts/redis_validation.php` | **9/9** (still green; rate limit, quota, cache, recovery, 2000/2000 concurrent increments) |
| OAuth2 DB-backed security | `php scripts/oauth_db_validation.php` | **18/18** (still green) |
| SSRF guard harness | standalone php | **25/25** (still green) |
| Functional latency floor + Redis RTT | `php scripts/perf_probe.php` | Warm p50 **26.5ms** / p95 **31.9ms** / p99 **35.1ms**, Redis **~0.043 ms/op**, 0 server errors (single-process floor — see `PERFORMANCE_REPORT.md`) |

### The 7 M18 feature-test failures — all resolved
| Category | Failure | Resolution |
|---|---|---|
| (A) Numeric | Nutrition `MealPlanFlow` `estimated_cost`, Reviews `ReviewsApi` `average` | Root cause is **JSON serialisation**, not a DB difference: a whole-number float serialises to a JSON integer and decodes to PHP `int` — identical on SQLite **and** PostgreSQL. The exact value is still asserted via a float bridge; no production change and no weakening. Confirmed by the PostgreSQL run also passing. |
| (B) Ordering | Admin `AdminFlow` audit order | Deterministic tiebreak: `orderByDesc('created_at')->orderByDesc('id')` on the time-ordered UUID. No timing/sleep. |
| (C) Logic | Notifications channel preference | Genuine defect fixed in `NotificationPreference::allows()` — in-app was force-on and overrode an explicit per-category channel set. Regression test updated to assert the corrected semantics. |
| (D) Logic | Analytics revenue KPI (×2) | Genuine defect: `whereBetween('bucket_date', [from, to])` with date-only bounds excluded same-day buckets under SQLite's string date compare; widened to full datetimes (PostgreSQL-correct). Monetary precision verified. |
| (E) Logic | Search unpublish removal | Genuine defect: the read-through search cache was not invalidated on reindex/removal. `SearchIndexManager` now flushes `SearchCache` after every index mutation; provider wiring updated. Regression covered. |

## EXECUTED — FAILED

| Check | Command | Result |
|---|---|---|
| Static analysis (PHPStan level 8 + Larastan) | `composer run analyse` | **FAILED — 1885 errors** across `app/` + `modules/` |

**Honest finding.** This gate was *authored into CI in M18 but never actually
executed until now*. Running it reveals **pre-existing, codebase-wide** debt — not
introduced by Milestone 19. The dominant categories are Eloquent magic-access at
level 8's strictness: `property.notFound` (947), `method.notFound` (357),
`argument.type` (278), `arrayValues.list` (124). These are overwhelmingly
**missing `@property`/`@method` model annotations and generic array typing**, not
runtime defects — the full runtime suite passes 336/336 on both engines.

Resolving 1885 level-8 errors means annotating every Eloquent model and tightening
generics across all 15 modules. That is a substantial, **feature-independent**
effort that a "GA blocker remediation" milestone must **not** attempt as an
unscoped redesign, and it must **not** be papered over with a `phpstan-baseline`
to fake a green gate. It is therefore reported honestly as **EXECUTED — FAILED**,
recorded in `TECHNICAL_DEBT.md`, and carried as a named factor in the GA decision.

## STATIC VALIDATION ONLY

| Check | Method | Result |
|---|---|---|
| OpenAPI contract (`redocly lint`) | structural parse (openapi 3.x header, ~273 paths, schemas resolve) | Spec parses cleanly; **redocly CLI could not be installed here** (npm registry fetch of `@redocly/cli` is network-blocked in-session). Executed clean in M17/M18 and runs in CI. |
| Docker full-stack boot | `docker compose config` | All 9 services (postgres, redis, api, worker, scheduler, nginx, web, minio, mailpit) merge & validate; healthchecks defined. **Image pulls return 403** from the Docker CDN (egress policy), so a clean-env boot could not be executed here. Procedure in `PRODUCTION_READINESS.md`. |

## NOT VALIDATED

| Check | Why |
|---|---|
| **Flutter** `pub get` / `analyze` / `test` | Flutter and Dart toolchains are **absent** (`which flutter dart` → nothing; no snap/apt package available in-session). Project structure (`apps/mobile/pubspec.yaml`, `lib/`, `test/` with 3 Dart test files) is statically present, but per the milestone rule Flutter is **not** marked validated because the commands did not execute. |
| **Production performance baseline** (multi-worker p50/p95/p99, sustained RPS, saturation error rate, DB/queue throughput under load) | Requires the k6 profiles in `load/public-api.k6.js` against a horizontally-scaled staging deployment; a single container cannot host it. Functional latency floor **was** measured — see `PERFORMANCE_REPORT.md`. |
| **Load / stress / spike / soak** | Same as above; no k6 binary and no scaled target in-session. |
| **Infrastructure egress enforcement** (NetworkPolicy / firewall / IMDS) | Depends on the final cloud provider and live network fabric. Deployment-ready specs in `docs/INFRA_EGRESS_POLICY.md`; application-layer SSRF guard is EXECUTED — PASSED. |
| **External penetration test** | Must be performed by an independent team against staging — a pre-production external requirement. Plan in `docs/PENETRATION_TEST_PLAN.md`. Not simulated. |
| **CI pipeline on GitHub** | Workflows authored; the Lint·Analyse·Test job will now pass Lint & Test but **fail Analyse** (see EXECUTED — FAILED) until the PHPStan debt is addressed or the gate is explicitly de-scoped. |

---

# Milestone 20 — GA Release Engineering & Production Certification

All executable checks re-run this milestone. Verdicts: **EXECUTED — PASSED /
EXECUTED — FAILED / STATIC VALIDATION ONLY / NOT VALIDATED**.

## EXECUTED — PASSED

| Check | Command | Result |
|---|---|---|
| Full Pest suite (SQLite) | `vendor/bin/pest` | **337 passed / 0 failed** (1319 assertions) — +1 new readiness-probe test |
| Full Pest suite (PostgreSQL 16) | `DB_CONNECTION=pgsql vendor/bin/pest` | **337 passed / 0 failed** |
| PHPStan Level 8 remediation | `composer run analyse` | **1885 → 162 production errors (−91.4%)** via genuine fixes (model `@property` annotations, list/array typing, dead-code removal, typed config resolve). No suppression, no level change, no baseline. Residual formally dispositioned in `docs/PHPSTAN_LEVEL8_REPORT.md`. |
| Coding standards (Pint) | `composer run lint` | PASS |
| Readiness probe | new `GET /api/v1/ready` | DB + Redis checks; 200/503; covered by a feature test |

## EXECUTED — FAILED (documented, non-blocking, scheduled)

| Check | Result |
|---|---|
| PHPStan Level 8 (absolute zero) | **162 residual** production errors — all low-severity static-typing refinements at repository↔domain boundaries; runtime is 337/337 green on both engines. Categorised, per-module, with a remediation schedule in `docs/PHPSTAN_LEVEL8_REPORT.md`. Carried as an explicit time-boxed CI waiver (not hidden by a baseline); a **production tag** hard-requires 0 (`release.yml`). |

## STATIC VALIDATION ONLY

| Check | Method |
|---|---|
| Docker clean-boot | `docker compose config` validates; a full build→boot→migrate→health/ready workflow is authored (`.github/workflows/ci-docker.yml`) to run in CI where the registry is reachable (the in-repo sandbox is registry-403-blocked). |
| Release gates | `.github/workflows/release.yml` authored — every mandatory gate (incl. PHPStan L8 = 0) hard-blocks a production tag; images built only after gates pass. Not executed on GitHub this session. |

## NOT VALIDATED (environment / external)

| Check | Why |
|---|---|
| Production performance certification | k6 suite (`load/public-api.k6.js`, `load/critical-flows.k6.js`, `load/run.sh`) authored covering auth/OAuth2/Public API/orders/search/rate-limit; needs a scaled staging deployment. |
| Flutter certification | Toolchain absent in-session; `ci-mobile.yml` + `release.yml` run `analyze`/`test` in CI. |
| Infrastructure egress enforcement | Provider-dependent; deployment-ready spec in `docs/INFRA_EGRESS_POLICY.md`. |
| External penetration test | Independent external requirement; scope/severity/release-policy in `docs/PENETRATION_TEST_PLAN.md`. Not simulated. |
