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
