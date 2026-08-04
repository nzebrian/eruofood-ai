# EruoFood AI — Penetration Test Handoff Package

For the **independent** security assessor. This package + `docs/PENETRATION_TEST_PLAN.md`
(the detailed scope and per-category test cases) is everything needed to conduct
the engagement against staging.

> EruoFood has **not** performed this test and does not self-attest it. It must be
> executed by an independent third party. Status stays **NOT VALIDATED — EXTERNAL
> REQUIREMENT** until the assessor delivers a report.

## 1. Architecture summary
- **Backend:** Laravel 12 / PHP 8.4 modular monolith (DDD, Clean Architecture),
  PostgreSQL 16, Redis 7, queue workers + scheduler, behind nginx.
- **Frontend:** React + TypeScript SPA. **Mobile:** Flutter.
- **Auth:** internal JWT (short-lived) + DB-backed refresh tokens; Public API via
  **API keys** (SHA-256 hashed) and **OAuth2** (Authorization Code + PKCE, Client
  Credentials, Refresh — DB-backed).
- **Public API:** `/api/public/v1/*` with per-scope authorization, Redis-backed
  rate limiting + quotas, HMAC-signed webhooks with SSRF guards.
- Diagrams/context: `docs/` (PUBLIC_API.md, API_SECURITY.md, WEBHOOKS.md,
  DEVELOPER_PLATFORM.md).

## 2. Staging target requirements (provided by EruoFood)
- Isolated **staging** URL (e.g. `https://staging.api.eruofood.ai`) with synthetic
  data only — never production PII.
- Infrastructure egress policy applied (`docs/INFRA_EGRESS_POLICY.md`) so SSRF
  defence-in-depth is testable end to end.
- A maintenance/testing window and a rollback contact.

## 3. Test accounts & credentials (issued for the engagement)
| Role | Purpose |
|---|---|
| Customer A / Customer B | BOLA/IDOR cross-user tests (orders, wallet) |
| Developer (portal) | API key + OAuth client provisioning |
| Admin (least-priv) + Admin (elevated) | RBAC / privilege-escalation tests |
| Support agent | support-surface authorization |
| OAuth2 client (auth-code + PKCE) and (client-credentials) | token flows |
| A staging Public API key (`efk_...`) | Public API + rate-limit/quota |

Credentials are delivered out-of-band at engagement start; none are in this repo.

## 4. Documentation for the assessor
- **OpenAPI spec:** `packages/api-contracts/openapi.yaml` (~273 paths).
- **OAuth flows:** `docs/PUBLIC_API.md` + `docs/API_SECURITY.md`.
- **Webhooks/SSRF:** `docs/WEBHOOKS.md` + `docs/INFRA_EGRESS_POLICY.md`.
- **Full test-case checklist:** `docs/PENETRATION_TEST_PLAN.md`.

## 5. Surfaces in scope
Authentication · OAuth2 · API keys · BOLA/IDOR · RBAC · **Admin** surfaces ·
**Payments/Wallet** surfaces (sandbox provider) · **Public API** · **Webhooks** ·
**SSRF** · **File uploads** · injection · rate limiting · session management ·
token security · privilege escalation. (Detailed cases: `PENETRATION_TEST_PLAN.md`
§3, mapped to OWASP API Top 10.)

## 6. Rules of engagement
- Staging only; no production; no destructive actions on shared infra; no
  exfiltration beyond PoC records the tester created.
- Coordinated window; report through a private channel with CVSS 3.1 severity and
  reproduction steps.
- Out of scope (unless separately authorised): third-party payment processors'
  own infra, physical/social engineering, volumetric DoS.

## 7. Severity classification (CVSS 3.1)
CRITICAL (9.0–10) · HIGH (7.0–8.9) · MEDIUM (4.0–6.9) · LOW (0.1–3.9) ·
INFORMATIONAL (0.0). Handling and release impact per `PENETRATION_TEST_PLAN.md` §5.

## 8. Release policy (binding)
- **No unresolved CRITICAL** at release.
- **No unresolved HIGH** without a written security acceptance (owner, rationale,
  compensating control, expiry).
- All MEDIUM triaged with owner + due date.

## 9. Retest requirements
- Every remediated **High/Critical** is retested by the assessor.
- The assessor issues a final report + attestation letter suitable for enterprise
  due-diligence, and a retest confirmation.

## 10. Deliverables expected back
Findings report (CVSS + repro + evidence), executive summary with a GA risk
recommendation, retest confirmation, attestation letter. On receipt, EruoFood
records the outcome in `docs/SECURITY_AUDIT.md` and flips the pentest line in
`VALIDATION_STATUS.md` accordingly.
