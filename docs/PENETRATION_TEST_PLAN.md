# EruoFood AI — Penetration Test Plan

**Status of an actual external penetration test: NOT PERFORMED.**

A true, independent penetration test **cannot be performed in this build
environment** and must not be simulated. This document is the **plan and scope**
for an external, independent security team to execute against a staging
deployment before production GA. It is a **pre-production external requirement**,
tracked as such in `docs/GA_DECISION.md`.

What *has* been executed in-repo is **automated security validation** (OAuth2
DB-backed 18/18, SSRF guard 25/25, BOLA order-authorization unit tests, secret
hashing) — see `docs/SECURITY_AUDIT.md`. That is not a substitute for an
adversarial human-driven pentest; it is the baseline the pentest starts from.

---

## 1. Engagement scope

### In scope
- Public API (`/api/public/v1/*`) — all read and order endpoints.
- Authentication: API keys **and** OAuth2 (Authorization Code + PKCE, Client
  Credentials, Refresh Token rotation) and the token/introspection endpoints.
- Authorization: scope enforcement and object-level authorization (BOLA/IDOR),
  especially `orders:*`.
- Developer portal + internal API (JWT-authenticated) and admin surfaces.
- Webhook subsystem (SSRF, delivery, signing) — application **and** the egress
  policy in `docs/INFRA_EGRESS_POLICY.md`.
- Payments and wallet flows (authorization, idempotency, amount/precision
  tampering) — logic only, against a sandbox payment provider.
- Rate limiting / quota enforcement (Redis-backed).
- File uploads (type/size/content validation, path handling).
- Session/token lifecycle (expiry, revocation, replay).

### Out of scope (unless separately authorised)
- Third-party payment processors' own infrastructure.
- Physical, social-engineering, and phishing vectors.
- Volumetric DoS/DDoS (rate-limit *correctness* is in scope; flooding is not).
- The upstream cloud provider's control plane.

### Rules of engagement
- Test against an isolated **staging** environment seeded with synthetic data —
  never production, never real customer PII.
- Coordinate a testing window; provide the team a rollback contact.
- No destructive actions against shared infrastructure; no data exfiltration of
  anything beyond proof-of-concept records created by the testers.
- All findings reported through a private channel with severity (CVSS 3.1) and
  reproduction steps.

---

## 2. Methodology

OWASP **API Security Top 10 (2023)** and **WSTG** as the baseline framework,
plus targeted abuse-case testing of the flows above. Grey-box: testers receive
API docs (`docs/PUBLIC_API.md`, the OpenAPI spec), test credentials at several
privilege levels, and this plan; they do **not** receive production secrets.

Tooling expectation (indicative): Burp Suite Professional, `ffuf`/`feroxbuster`,
`nuclei`, `sqlmap` (targeted), a custom OAuth/PKCE test harness, and manual
analysis. Automated scanners are a starting point, not the deliverable.

---

## 3. Test cases by category

### API1 — Broken Object Level Authorization (BOLA / IDOR)
- [ ] Enumerate `/orders/{id}` with user A's credential against user B's order ids → expect 403/404, never data.
- [ ] `/orders/{id}/cancel`, `/orders/{id}/status` cross-user → refused.
- [ ] An application-level (no-subject) key/token is refused for all `orders:*` routes.
- [ ] Menu/product/nutrition/recipe endpoints never leak unpublished/draft/soft-deleted resources.
- [ ] Search results never surface unpublished content (regression-tested in-app; confirm at HTTP layer).

### API2 — Broken Authentication
- [ ] Malformed / truncated / expired API keys and OAuth tokens → 401.
- [ ] Revoked key/token → 401.
- [ ] Token endpoint rate-limited against credential stuffing / brute force.
- [ ] Algorithm/format confusion between API keys and OAuth bearers.
- [ ] JWT tampering on the developer portal (alg=none, key confusion, expired, wrong audience).

### API3 — Broken Object Property Level Authorization
- [ ] Mass-assignment: post extra fields (status, owner_id, price) on order create → ignored/rejected.
- [ ] Response does not over-expose internal fields (hashes, internal ids, other users' data).

### API4 — Unrestricted Resource Consumption
- [ ] Burst past per-minute/burst limits → `429` with `Retry-After`.
- [ ] Exhaust daily/monthly quota → quota error; counters are strictly per-client.
- [ ] Oversized pagination / payloads / deep query params → clamped or rejected.
- [ ] File upload size/count limits enforced.

### API5 — Broken Function Level Authorization / Privilege escalation
- [ ] `foods:read` credential against `orders:*`; `search:read` against `orders:*` → 403.
- [ ] Request scopes beyond the grant → issued token must not contain them.
- [ ] Widen scope on refresh → must only narrow.
- [ ] Non-admin reaching admin endpoints; horizontal role bypass.

### API6 — Unrestricted Access to Sensitive Business Flows
- [ ] Automate order/refund/wallet flows past intended limits → business-logic guards hold.
- [ ] Payment amount / currency / precision tampering (negative, overflow, sub-cent) → rejected; monetary precision preserved.
- [ ] Idempotency-key replay on payment/order create → single effect, not duplicated.

### API7 — Server-Side Request Forgery (webhooks)
- [ ] Register webhook URLs pointing at `169.254.169.254`, `127.0.0.1`, RFC1918, `[::1]`, CGNAT, and DNS names resolving to internal IPs → all refused.
- [ ] DNS-rebinding: name public at registration, private at delivery → delivery refused.
- [ ] Redirects to internal hosts are not followed; response size/time capped.
- [ ] Confirm the **infrastructure egress policy** (`INFRA_EGRESS_POLICY.md`) blocks anything that slips the app layer — run the §3 acceptance test from a worker pod.

### API8 — Security Misconfiguration
- [ ] HTTPS enforced; HSTS present; CORS allow-list correct (no `*` with credentials).
- [ ] No stack traces / debug output in error responses.
- [ ] `Cache-Control: no-store` on token responses.
- [ ] Internal/developer-portal endpoints reject API keys (JWT only).
- [ ] Security headers (CSP where applicable, `X-Content-Type-Options`, etc.).

### API9 — Improper Inventory Management
- [ ] No undocumented/legacy API versions reachable; deprecated endpoints removed or gated.
- [ ] Non-production/debug endpoints not exposed in staging-as-prod config.

### API10 — Unsafe Consumption of APIs / Injection
- [ ] SQL/NoSQL injection across all query/filter/search params (parameterised queries expected).
- [ ] Command / template / header injection; log injection.
- [ ] Stored/reflected XSS in any content rendered by the web/developer portal.
- [ ] Path traversal in file endpoints and any id-to-path mapping.

### OAuth2 / PKCE (deep-dive)
- [ ] Exchange with missing / wrong / `plain`-downgraded verifier → rejected.
- [ ] Replay a used authorization code → rejected.
- [ ] Replay a rotated refresh token → rejected (reuse detection revokes the chain).
- [ ] Unregistered / mismatched `redirect_uri` at authorize and at exchange → rejected.
- [ ] Open-redirect / path-traversal variants of registered redirect URIs.
- [ ] Client isolation: client A's refresh/code used by client B → rejected.

### Session & token lifecycle
- [ ] Access token used after expiry / after revocation → rejected.
- [ ] Concurrent-session and logout-everywhere semantics behave as documented.

---

## 4. Deliverables from the external team
- Findings report with CVSS 3.1 severities, reproduction steps, and evidence.
- Executive summary with a GA risk recommendation.
- Retest of all High/Critical findings after remediation.
- Attestation letter suitable for enterprise customer due-diligence.

## 5. Severity classification & handling (CVSS 3.1)

| Severity | CVSS | Handling | Release impact |
|---|---|---|---|
| **CRITICAL** | 9.0–10.0 | Fix immediately; block release; hotfix + retest before any deploy | **Hard block** — no release with an open Critical |
| **HIGH** | 7.0–8.9 | Fix before GA; block release unless an explicit, written **security acceptance** (risk owner + expiry + compensating control) is signed | **Block** unless formally accepted in writing |
| **MEDIUM** | 4.0–6.9 | Triage; scheduled remediation with owner + due date; may ship with a tracked plan | Does not block, but must be logged |
| **LOW** | 0.1–3.9 | Backlog with a target milestone | Does not block |
| **INFORMATIONAL** | 0.0 | Note as hardening advice; no obligation | Does not block |

## 6. Release policy (binding)

- **No unresolved CRITICAL findings** may exist at release. Non-negotiable.
- **No unresolved HIGH findings** without an explicit **security acceptance**
  recorded by the risk owner (name, date, rationale, compensating control, and an
  expiry by which it must be remediated).
- All MEDIUM findings triaged with an owner and due date before GA.
- These rules are enforced as a manual release gate in
  `docs/GA_RELEASE_CHECKLIST.md` and by the Incident Commander / security owner
  sign-off, in addition to the automated gates in `.github/workflows/release.yml`.

## 7. Exit criteria for production GA
- **Zero** open Critical or High findings (High only shippable with signed
  acceptance per §6).
- All Medium findings triaged with an accepted remediation plan and owner.
- SSRF infra acceptance test (§3, API7) passed from a real worker pod.
- Retest attestation received for every High/Critical that was remediated.

---

## Status

An external, independent penetration test is **NOT VALIDATED / NOT PERFORMED**.
It remains a pre-production external requirement and must be carried out by an
independent security professional against staging before GA. This document is the
scope and rules of engagement for that test — it is not evidence that a test
occurred.
