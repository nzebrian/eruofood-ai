# API Security

Security controls for the Public API & Developer Platform (Milestone 16),
followed by an OWASP API Security Top 10 (2023) review.

## Controls implemented

| Control | Where | Notes |
|---|---|---|
| **API-key auth** | `AuthenticateApiKey` middleware | Bearer/`X-Api-Key`; resolves prefix → key → application. |
| **Key hashing** | `Sha256SecretHasher` | Only the hash + public prefix are stored; plaintext shown once. Verify is constant-time (`hash_equals`). |
| **CSPRNG secrets** | `ApiKeyService` | Secret from `random_bytes(32)`; prefix from `random_bytes(4)`. |
| **Key rotation / revocation / expiry** | `ApiKeyService` | Rotate = revoke + reissue; revoke is immediate; optional `ttl_days`. |
| **Scoped authorization** | `EnforceScope` middleware + `ScopeSet` | Per-route required scope; keys hold only granted scopes (never widened past the app). |
| **Rate limiting + burst** | `ApiRateLimit` + `RateLimitService` | Per-client fixed windows (per-minute + 10s burst), Redis-backed; `429` + `Retry-After`. |
| **Quotas** | `ApiQuota` + `QuotaService` | Daily + monthly per client; `429` when exhausted. |
| **Request validation** | Controllers (`$request->validate`) + VO invariants | Scope format, URLs, pagination clamps, typed value objects. |
| **Audit logging** | `publicapi.request_served` + auth/rate/quota events | Every request and every rate/quota breach is an event (→ Analytics). |
| **Webhook signing** | `WebhookSigner` (HMAC-SHA256) | `"{ts}.{body}"`; receivers verify constant-time. |
| **Webhook replay protection** | `WebhookSigner::verify` | Timestamp tolerance (300s) + signed timestamp. |
| **Webhook idempotency** | unique `(webhook, event_id)` | One delivery per event; retried deliveries dedupe. |
| **Secret storage at rest** | `encrypted` cast on webhook secret | Signing secrets encrypted in the DB (must be retrievable to sign). |
| **CORS** | `publicapi.cors` config | Explicit allowed origins/headers; empty by default (deny-by-default in prod). |
| **Version isolation** | route-group per version | v2 cannot alter v1; deprecation via `Deprecation`/`Sunset` headers. |

## OWASP API Security Top 10 (2023) review

| # | Risk | Status | How it's addressed / residual |
|---|---|---|---|
| **API1** | Broken Object Level Authorization | **Addressed** | Every developer-platform lookup enforces ownership (`Application::isOwnedBy`, `Webhook::isOwnedBy`). Public data is published, non-owned content only. Residual: `orders:*` endpoints (not yet wired) must scope to the caller when added. |
| **API2** | Broken Authentication | **Addressed** | Hashed keys, constant-time verify, revoke/expiry checked on every request, suspended apps rejected. No plaintext secret stored. |
| **API3** | Broken Object Property Level Authorization | **Addressed** | Transformers emit an explicit allow-list of fields; key/webhook secrets and hashes are never serialized (except the one-time plaintext at mint). |
| **API4** | Unrestricted Resource Consumption | **Addressed** | Per-client rate limits + burst window + daily/monthly quotas; `per_page` clamped; webhook attempts capped. |
| **API5** | Broken Function Level Authorization | **Addressed** | Public surface is read-only + scope-gated; management is JWT-only under a separate prefix; the two never mix. |
| **API6** | Unrestricted Access to Sensitive Business Flows | **Partly** | Rate/quota throttling curbs automated abuse; abuse signals emitted as events. Residual: add per-endpoint anomaly detection when write flows (orders) go live. |
| **API7** | Server-Side Request Forgery | **Addressed (webhooks)** | Webhook URLs are developer-owned outbound targets over a bounded-timeout client; no user-supplied URL is fetched server-side on the read path. Residual: add egress allow-listing / block private ranges for webhook targets in production. |
| **API8** | Security Misconfiguration | **Addressed** | Deny-by-default CORS, stateless API, standard error envelope (no stack traces), secrets via env, keys/secrets never logged. |
| **API9** | Improper Inventory Management | **Addressed** | Single versioned OpenAPI spec (tag *Public API*), `/status` lists versions, deprecation headers; internal vs public surfaces are clearly separated. |
| **API10** | Unsafe Consumption of 3rd-party APIs | **N/A / minimal** | The public API exposes first-party data; webhook responses are treated as untrusted (status only, bounded timeout, no body execution). |

### Recommended hardening before GA

- Webhook egress allow-listing (block RFC-1918 / link-local targets) — API7.
- Per-endpoint anomaly/abuse detection for write flows — API6.
- Optional key IP allow-listing and per-key (not just per-app) rate tiers.
- Rotate the app encryption key policy for webhook secrets.

## Secrets policy

- **API-key secrets:** hashed (SHA-256), never stored or logged in plaintext,
  displayed once at issue/rotate.
- **Webhook signing secrets:** stored encrypted at rest (retrievable only to
  sign), displayed once at create/rotate.
- **Provider/JWT secrets:** environment variables (see `.env.example`), never
  committed.

---

## OWASP API Security Top 10 (2023) review — Milestone 17

| # | Risk | Posture in EruoFood Public API |
|---|---|---|
| API1 | **Broken Object Level Authorization (BOLA)** | The authenticated **subject user** is the *only* source of the customer id passed downstream — never a client-supplied parameter. Customer-scoped API keys and user-delegated OAuth tokens carry `subject_user_id`; application-level credentials (no subject) are refused for order resources. The Commerce `OrderService` re-checks ownership (defence in depth), throwing → `403`. Covered by design + tests. |
| API2 | **Broken Authentication** | Two mechanisms, one model: API keys (hashed, never stored plaintext, constant-time verify) and OAuth2 (Auth Code+PKCE, Client Credentials, Refresh with rotation; tokens/codes stored only as hashes). Both resolve through a `PrincipalResolver` chain to one `AuthenticatedContext`. Token endpoint is rate-limited. |
| API3 | **Broken Object Property Level Authorization** | Responses are built by explicit **transformers** (allow-lists of fields) from the module's own DTOs — internal entities are never serialised directly, so no mass-assignment or over-exposure. Writes use `$request->validate([...])` allow-lists. |
| API4 | **Unrestricted Resource Consumption** | Per-client rate limiting (burst-aware, `X-RateLimit-*`), daily/monthly quotas (`X-Quota-*`), pagination capped at `max_per_page`, bounded webhook timeouts/response size. (Redis-backed behaviour is a GA validation item.) |
| API5 | **Broken Function Level Authorization** | Every data route requires a specific granted scope (`publicapi.scope:<scope>`); scopes are the intersection of application grant ∩ credential request and never widen. Developer/portal endpoints are JWT-only and never accept API keys. |
| API6 | **Unrestricted Access to Sensitive Business Flows** | Order creation goes through the Order domain's checkout (never a bypass); cancellation is allowed only where domain rules permit. Rate limits + quotas blunt automated abuse. |
| API7 | **Server-Side Request Forgery (SSRF)** | Webhook destinations are validated (scheme/port/credentials + private/reserved/loopback/link-local/CGNAT/IPv6-ULA blocking), re-validated at send time (DNS-rebinding), with redirects disabled and size/time caps. Infra egress controls documented as a GA requirement. See `WEBHOOKS.md`. |
| API8 | **Security Misconfiguration** | HTTPS enforced in prod; strict CORS allow-list; standard error envelope (no stack traces); `Cache-Control: no-store` on token responses; secrets via env. |
| API9 | **Improper Inventory Management** | The public surface is versioned (`/api/public/v1`), a single OpenAPI contract documents every endpoint + security scheme, and the developer portal is separate from the data surface. Internal endpoints are never proxied. |
| API10 | **Unsafe Consumption of APIs** | The only outbound calls are webhooks, hardened as above; source-context reads go through in-process ports, not untrusted network calls. |

### Object-level authorization (BOLA/IDOR) design note

The gateway attaches `publicapi_subject_user_id` from the credential. `OrderApiService`
calls `Principal::requireSubjectUser()` — which throws `403` for an
application-level credential — and passes that id (never a request value) to the
Order domain. A caller therefore cannot enumerate or act on another user's
orders even by guessing ids: the id in the URL selects a resource, but ownership
is decided by the authenticated subject, and the domain rejects a mismatch.
