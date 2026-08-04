# ERUOFOOD AI — PRODUCTION GA DECISION

**Milestone 19 — GA Blocker Remediation & Final Production Validation**
Date: 2026-08-03 · Decision owner: Lead Software Architect

> This decision uses only evidence that was **actually executed in this session**,
> classified as EXECUTED — PASSED / EXECUTED — FAILED / STATIC VALIDATION ONLY /
> NOT VALIDATED. Nothing is fabricated. A GO is **not** declared merely because
> most tests pass.

---

## 1. Test totals (this milestone, re-run)

| Suite | Engine | Result |
|---|---|---|
| API — Pest (unit + feature) | SQLite `:memory:` (canonical) | **336 passed / 0 failed / 0 skipped** — 1313 assertions, 24.4s |
| API — Pest (unit + feature) | **PostgreSQL 16.13** (production engine) | **336 passed / 0 failed / 0 skipped** |
| Web — Vitest | jsdom | **51 passed / 51** (15 files) |
| Web — type-check / build | `tsc` / `vite` | Exit 0 / Exit 0 |

**Remaining test failures: ZERO** on both database engines. All 7 Milestone-18
failures are fixed (2 JSON-serialisation assertion artefacts confirmed identical
on both engines; 3 genuine logic defects fixed with regressions; 1 nondeterministic
ordering fixed with a UUID tiebreaker). No test was skipped, weakened, or deleted
to reach green.

## 2. Static quality gates

| Gate | Result | Verdict |
|---|---|---|
| Pint (coding standards, psr12) | Conformed via `lint:fix`; `pint --test` passes | **EXECUTED — PASSED** |
| PHPStan level 8 + Larastan | **1885 errors** (pre-existing; model annotations/generics, not runtime defects) | **EXECUTED — FAILED** |

## 3. Infrastructure & runtime validation

| Area | Verdict |
|---|---|
| PostgreSQL 16 — migrations from empty DB, full suite, rollback | **EXECUTED — PASSED** |
| Redis 7 — rate limit / quota / idempotency / counters (2000/2000 concurrent) | **EXECUTED — PASSED** (9/9) |
| OAuth2 DB-backed security (PKCE, rotation, isolation, scope, revocation) | **EXECUTED — PASSED** (18/18) |
| Webhook SSRF — application layer | **EXECUTED — PASSED** (25/25) |
| Functional latency floor (p50 26.5 / p95 31.9 / p99 35.1 ms; Redis 0.043 ms/op) | **EXECUTED — MEASURED** (single-process floor) |
| Docker full-stack boot (9 services) | **STATIC VALIDATION ONLY** — compose config valid; image pulls 403-blocked in-session |
| OpenAPI contract (redocly) | **STATIC VALIDATION ONLY** in-session (parses; CLI install network-blocked); green in CI/M17–M18 |
| Production performance baseline (multi-worker, scaled) | **NOT VALIDATED** — needs k6 on staging |
| Load / stress / spike / soak | **NOT VALIDATED** — no k6 / scaled target in-session |
| Flutter — pub get / analyze / test | **NOT VALIDATED** — toolchain absent (structure present) |
| Infrastructure egress enforcement | **NOT VALIDATED** — provider-dependent; deployment-ready spec authored |
| External penetration test | **NOT PERFORMED** — external requirement; plan authored |

## 4. Security posture

Application-layer controls are EXECUTED — PASSED: API-key + OAuth2 auth,
object-level authorization (BOLA/IDOR), scope enforcement, webhook SSRF guard,
secret hashing (SHA-256, constant-time), rate limiting/quotas. Infrastructure
egress controls are specified (`docs/INFRA_EGRESS_POLICY.md`) but NOT enforced
in-session. Dependency/secret scanning is authored in CI (STATIC VALIDATION ONLY).

## 5. Remaining vulnerabilities / risks

- **No known application-layer vulnerability is open** from the executed checks.
- **Residual risk pending external validation:** the DNS-rebinding/TOCTOU webhook
  race is only fully closed once the infrastructure egress policy is enforced; and
  no adversarial human pentest has been run. Both are pre-production requirements,
  not evidence of a known live flaw.

## 6. Remaining technical debt

- **TD-M19-1:** PHPStan level-8 gate red — 1885 pre-existing errors (model
  annotations/generics). Not runtime defects. Remediation is a dedicated typing
  milestone (or a reviewed, documented baseline). **Not** silently suppressed.
- **TD-M19-3:** environment-limited validations (Docker boot, Flutter, k6,
  redocly, external pentest, infra egress) — each with a ready runbook.
- Full backlog in `docs/TECHNICAL_DEBT.md`.

## 7. External / infrastructure requirements (cannot be done in this build env)

1. Independent external penetration test against staging — `docs/PENETRATION_TEST_PLAN.md`.
2. Infrastructure egress enforcement on the chosen cloud — `docs/INFRA_EGRESS_POLICY.md`.
3. k6 performance/load/soak run against scaled staging — `load/public-api.k6.js`.
4. Clean-environment full Docker stack boot in staging.
5. Flutter `analyze` + `test` on a real toolchain.

---

## 8. DECISION: **NO-GO** for full-platform production GA

The platform's core is genuinely strong and **fully green at runtime** — the
entire API test suite passes 336/336 on both SQLite and PostgreSQL, with auth,
authorization, OAuth2, Redis primitives, and the webhook SSRF guard all EXECUTED —
PASSED, and the web app building and testing clean. The **Public API surface is
functionally GA-ready.**

However, a full-platform **GO cannot be declared**, because these blockers remain
and at least one is a hard, non-negotiable pre-production requirement:

1. **PHPStan level-8 gate is red (1885 errors)** — a CI quality gate is failing.
   Must be remediated or explicitly, formally de-scoped/baselined with sign-off.
2. **No production performance baseline** — capacity, p95/p99 under real
   concurrency, and saturation behaviour are unproven at scale.
3. **Full Docker stack not booted** from a clean environment in this session.
4. **Flutter mobile not validated** — toolchain absent.
5. **Infrastructure egress controls not enforced** — the webhook SSRF defence is
   not complete without them.
6. **No independent external penetration test** has been performed.

**If and only if** items 1–5 are cleared in staging and the **sole** remaining
item were the external penetration test, the decision would be:
*"Conditional GO, pending independent external penetration-test sign-off (zero
open High/Critical findings)."* Today, more than that remains outstanding, so the
formal decision is **NO-GO**, with the exact blocker list above.

This is an honest engineering verdict: the build is in strong shape and the path
to GA is clear and short, but it is not there yet.
