# EruoFood AI — Security Audit (Milestone 18)

Verdicts use the four labels: **EXECUTED — PASSED**, **EXECUTED — FAILED**,
**STATIC VALIDATION ONLY**, **NOT VALIDATED**.

## 1. Authentication & OAuth2 — EXECUTED — PASSED (DB-backed)

`scripts/oauth_db_validation.php` ran the OAuth2 server end-to-end against
persisted Eloquent repositories (real database, not in-memory) — **18/18**:

| Control | Result |
|---|---|
| Authorization Code + PKCE (S256) | PASSED |
| PKCE bypass attempt (wrong verifier) | Rejected (`invalid_grant`) |
| Single-use authorization codes (replay) | Rejected on reuse |
| redirect_uri mismatch at exchange | Rejected (`invalid_grant`) |
| Unregistered redirect_uri at authorize | Rejected (`invalid_request`) |
| Client Credentials — no subject, no refresh token | PASSED (token carries no BOLA subject) |
| Wrong client secret | Rejected (`invalid_client`) |
| Refresh rotation + reuse detection | Old refresh token revoked on rotation |
| Scope escalation (requesting ungranted scopes) | Clamped to client-allowed scopes |
| Client isolation (cross-client refresh reuse) | Rejected |
| Expired access token | Rejected |
| Revoked access token | Rejected |

API-key authentication is unchanged and covered by the Pest suite
(`PublicApiFlowTest`, `ScopeAndKeyTest`) — EXECUTED — PASSED.

## 2. Object-level authorization (BOLA/IDOR) — EXECUTED — PASSED

- Unit: `OrderAuthorizationTest` — the subject user reaching the Order domain is
  always the authenticated principal, never a client-supplied id; app-level
  credentials are refused for customer orders.
- The OAuth introspection test confirms tokens carry the delegated subject and
  client-credentials tokens carry none, so machine tokens cannot reach
  customer-owned resources.

## 3. Webhook SSRF — EXECUTED — PASSED (application layer)

`WebhookSecurityTest` + `scripts` (M17) — destination validation blocks
loopback/private/link-local/CGNAT/IPv6-ULA/mapped ranges, credentials,
disallowed schemes/ports; redirects disabled; DNS re-checked at send time.

### Infrastructure egress controls — NOT VALIDATED (must be applied in prod)

Application-level DNS validation cannot alone defeat a resolver that changes the
address at connection time. Production **must** add, at the infrastructure layer
(documented in `WEBHOOKS.md`):

- Egress proxy / firewall denying RFC1918, loopback, link-local, CGNAT and IPv6
  ULA ranges for the webhook workers.
- Explicit block of the cloud metadata endpoint `169.254.169.254`.
- Outbound allowed only on `:443` (and `:80` if used).
- A dedicated low-privilege egress path/security-group for webhook delivery.

## 4. Secret handling — EXECUTED — PASSED

API-key secrets, OAuth access/refresh tokens, and authorization codes are stored
only as SHA-256 hashes (verified constant-time); plaintext is returned once at
issue and never persisted. Confirmed by `ScopeAndKeyTest` and the OAuth
validation script.

## 5. Dependency & secret scanning — STATIC VALIDATION ONLY

`security.yml` runs Gitleaks + `npm audit` + `composer audit` on CI. Authored
and configured; not executed in this session.

## 6. OWASP API Security Top 10

See `API_SECURITY.md` for the full mapping (API1–API10). All application-level
controls are implemented and, where testable, executed above.

---

## External penetration-test checklist (for a real pentest engagement)

A true external penetration test could **not** be performed in this environment.
The following checklist is prepared for an external team to execute against a
staging deployment:

### Broken Object Level Authorization (BOLA / IDOR)
- [ ] Enumerate `/orders/{id}` with a valid key for user A against user B's order ids → expect 403/404, never data.
- [ ] Attempt `/orders/{id}/cancel` and `/orders/{id}/status` cross-user.
- [ ] Confirm an application-level (no-subject) key/token is refused for all `orders:*` routes.
- [ ] Verify menu/product/nutrition endpoints never leak unpublished/draft resources.

### Broken authentication
- [ ] Present malformed / truncated / expired API keys and OAuth tokens → 401.
- [ ] Present a revoked key/token → 401.
- [ ] Confirm the token endpoint is rate-limited against credential stuffing.
- [ ] Attempt algorithm/format confusion between API keys and OAuth bearers.

### Privilege / scope escalation
- [ ] Request scopes beyond the application grant → issued token must not contain them.
- [ ] Use a `foods:read` credential against `orders:*`, `search:read` against `orders:*` → 403.
- [ ] Attempt to widen scope on refresh → must only narrow.

### Token replay & session
- [ ] Replay a used authorization code → rejected.
- [ ] Replay a rotated refresh token → rejected (reuse detection).
- [ ] Replay a captured access token after revocation/expiry → rejected.

### PKCE
- [ ] Exchange with a missing / wrong / `plain`-downgraded verifier → rejected.
- [ ] Intercept a code without the verifier and attempt exchange → rejected.

### Redirect URI
- [ ] Exchange with an unregistered or mismatched `redirect_uri` → rejected.
- [ ] Test open-redirect / path-traversal variants of registered URIs.

### SSRF (webhooks)
- [ ] Register webhook URLs pointing at `169.254.169.254`, `127.0.0.1`, RFC1918, `[::1]`, DNS names resolving to internal IPs → all refused.
- [ ] DNS-rebinding: a name that resolves public at registration, private at delivery → delivery refused.
- [ ] Confirm redirects are not followed and response size/time are capped.
- [ ] Confirm the infrastructure egress policy (above) blocks any that slip the app layer.

### Rate limiting / resource consumption
- [ ] Burst past the per-minute/burst limits → 429 with `Retry-After`.
- [ ] Exhaust daily/monthly quota → quota error; confirm counters are per-client.
- [ ] Oversized pagination / payloads → clamped / rejected.

### Misconfiguration
- [ ] Confirm HTTPS enforced; CORS allow-list correct; no stack traces in errors.
- [ ] Confirm `Cache-Control: no-store` on token responses.
- [ ] Confirm internal/developer-portal endpoints reject API keys (JWT only).
