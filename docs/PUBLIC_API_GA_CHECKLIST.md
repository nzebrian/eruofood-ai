# Public API — GA Readiness Checklist

Milestone 17 (Public API Completion & GA Hardening). This checklist tracks what
is **done in code**, what is **validated**, and what remains a **GA blocker**
requiring a real runtime environment. Nothing here is marked complete unless the
corresponding code exists; validation status is reported honestly per
`VALIDATION_STATUS.md`.

Legend: ✅ done · 🟡 done in code, runtime validation pending · ⛔ GA blocker
(needs infra/runtime not available in this environment).

## 1. Resource coverage

| Item | Status | Notes |
|---|---|---|
| Foods, Recipes (read) | ✅ | From M16. |
| Restaurants + menus (read) | ✅ | `restaurants:read`, via Marketplace read port. |
| Products + categories (read) | ✅ | `products:read`, via Commerce read port. |
| Nutrition data (read) | ✅ | `nutrition:read`, via Nutrition read port. |
| Search + suggestions + filters | ✅ | `search:read`, via Search context pipeline. |
| Orders read (list/get/status) | ✅ | `orders:read`, BOLA-enforced. |
| Orders write (create/cancel) | ✅ | `orders:write`, via the Order domain (never bypassed). |
| Bounded-context isolation | ✅ | Every resource goes through a read/order **port**; no cross-context table access. |

## 2. Authentication & authorization

| Item | Status | Notes |
|---|---|---|
| API-key auth (unchanged) | ✅ | The key path is untouched; wrapped by a resolver. |
| OAuth2 Authorization Code + PKCE | 🟡 | Implemented; unit-validated with in-memory repos. DB-backed flow pending a runtime. |
| OAuth2 Client Credentials | 🟡 | Implemented; unit-validated. |
| OAuth2 Refresh Token (rotation) | 🟡 | Implemented; unit-validated. |
| Same scope model for keys & tokens | ✅ | Both resolve to one `AuthenticatedContext`. |
| Object-level authorization (BOLA/IDOR) | ✅ | Subject user comes only from the credential; the Order domain re-checks ownership. |
| Scope enforcement per endpoint | ✅ | `publicapi.scope:<scope>` on every data route. |

## 3. Webhook security

| Item | Status | Notes |
|---|---|---|
| SSRF destination validation | ✅ | Scheme/port/credentials + private/reserved/loopback/link-local/CGNAT/IPv6-ULA blocking. |
| DNS-rebinding protection | ✅ | Re-validated at send time, not only at registration. |
| Redirect blocking | ✅ | `withoutRedirecting()` on the dispatcher. |
| HTTPS enforced in production | ✅ | Config-driven (`enforce_https`, default on in prod). |
| Timeouts + response size cap | ✅ | Connect timeout + `CURLOPT_MAXFILESIZE`. |
| HMAC signing + replay window | ✅ | From M16 (`WebhookSigner`, timestamp tolerance). |
| Secret rotation | ✅ | From M16. |
| Infra-level egress controls | ⛔ | Must be enforced by network policy/proxy in prod — see `WEBHOOKS.md`. App code cannot fully guarantee it alone. |

## 4. Infrastructure validation (require a runtime)

| Item | Status | Notes |
|---|---|---|
| Redis-backed rate limiting | ⛔ | Not validated against real Redis in this environment. |
| Redis-backed quotas | ⛔ | Not validated against real Redis. |
| Redis idempotency / distributed counters | ⛔ | Not validated against real Redis. |
| Concurrency / load / soak tests | ⛔ | No load-test environment available. |
| Performance (p50/p95/p99, throughput) | ⛔ | Not measured — **Not Validated**. |

## 5. Contract & SDKs

| Item | Status | Notes |
|---|---|---|
| OpenAPI: new endpoints documented | ✅ | Restaurants, products, nutrition, search, orders, OAuth. |
| OpenAPI: security schemes/scopes | ✅ | `apiKeyAuth` + `oauth2` (authCode + clientCreds flows). |
| OpenAPI: 0 errors (redocly) | ✅ | 411 style warnings remain (pre-existing operationId/4xx). |
| No duplicate schemas / broken $ref | ✅ | 212 uniquely-named schemas; redocly resolves all refs. |
| TypeScript SDK updated | ✅ | New resources + POST + `oauthToken()`; `tsc --strict` clean. |
| PHP SDK updated | ✅ | New resources + POST; `php -l` clean. |
| Dart SDK updated | 🟡 | New resources + POST added; Dart toolchain absent (static only). |

## 6. Testing

| Item | Status | Notes |
|---|---|---|
| SSRF guard tests | ✅ Executed | Standalone harness 25/25 + Pest `WebhookSecurityTest`. |
| OAuth grant tests | ✅ Executed | Standalone harness 18/18 + Pest `OAuthServiceTest`. |
| Read-port / resource sanity | ✅ Static | `php -l` + cross-ref check across 151 files. |
| BOLA/IDOR order tests | 🟡 | Design-enforced + Pest written; full HTTP feature run needs the framework. |
| Pest full suite execution | ⛔ | `composer install` cannot finalize here (see `VALIDATION_STATUS.md`). |

## GA blockers (must clear before production)

1. Run the full Pest suite in CI with the framework installed.
2. Validate Redis-backed rate limiting, quotas and idempotency against a real
   Redis, including concurrency/soak/failure-recovery.
3. Run a load test and record p50/p95/p99, throughput and error rate.
4. Enforce webhook egress at the network layer (egress proxy / firewall policy)
   in addition to the in-app SSRF guard.
5. Penetration-test the OAuth2 flows end-to-end against the database-backed
   implementation.

---

## Milestone 18 update — GA blockers re-assessed against a real runtime

The prior blockers were validated in a live PHP 8.4 / PostgreSQL 16 / Redis 7
environment. Status now (see `PRODUCTION_READINESS.md` for evidence):

| Prior GA blocker | Status now |
|---|---|
| Run the full Pest suite in CI | **EXECUTED** — 328/335 pass on SQLite; 8 real defects fixed. CI upgraded with PG+Redis services (runs on GitHub). |
| Validate Redis (rate limit, quota, idempotency) against real Redis | **EXECUTED — PASSED** (9/9, incl. 2000/2000 concurrent atomic increments). |
| Load test + p50/p95/p99 baseline | **NOT VALIDATED** — k6 script provided; needs a staging run. |
| Webhook egress at the network layer | **NOT VALIDATED** — documented in `WEBHOOKS.md` / `SECURITY_AUDIT.md`; infra task. |
| Pen-test the DB-backed OAuth2 flow | **EXECUTED — PASSED** for the automated security checks (18/18); external pen-test checklist prepared in `SECURITY_AUDIT.md`. |
| Migrate from an empty PostgreSQL database | **EXECUTED — PASSED** (101 migrations, rollback clean). |

Remaining before full-platform production GO: performance baseline, the 3
genuine feature-logic test failures (Notifications channel preference, Analytics
revenue KPI, Search unpublish removal), full Docker stack boot, Flutter
analyze/test, and the infra egress + external pen-test.

---

## Milestone 19 update — final validation

| Prior blocker | Status now |
|---|---|
| Run the full Pest suite green | **EXECUTED — PASSED, 336/336** on SQLite **and** PostgreSQL 16 (all 7 M18 failures fixed). |
| Redis rate limit / quota / idempotency vs real Redis | **EXECUTED — PASSED** (9/9, still green). |
| DB-backed OAuth2 security | **EXECUTED — PASSED** (18/18, still green). |
| Webhook egress at the network layer | Deployment-ready spec in `docs/INFRA_EGRESS_POLICY.md`; infra enforcement **NOT VALIDATED** (provider-dependent). App-layer SSRF guard EXECUTED — PASSED (25/25). |
| Load test + p50/p95/p99 baseline | Functional latency floor **measured** (`scripts/perf_probe.php`); production baseline **NOT VALIDATED** — needs k6 on scaled staging. |
| External pen-test of OAuth2 | Formal plan authored (`docs/PENETRATION_TEST_PLAN.md`); external pentest **NOT PERFORMED** (pre-production external requirement). |
| Coding standards (Pint) | **EXECUTED — PASSED** after `lint:fix`. |
| Static analysis (PHPStan L8) | **EXECUTED — FAILED** — 1885 pre-existing errors (model annotations/generics), a named GA factor; see `TECHNICAL_DEBT.md`. |

**Public API surface:** functionally GA-ready and green end-to-end. Full-platform
GA is **NO-GO** pending the items in `docs/GA_DECISION.md` (PHPStan gate,
production perf baseline, Docker boot, Flutter, infra egress, external pentest).
