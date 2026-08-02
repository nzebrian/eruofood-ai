# PublicApi Module (`EruoFood\PublicApi`)

The **Public API & Developer Platform** bounded context — EruoFood's controlled
external-facing surface plus the developer portal behind it. It is a **façade**,
not a re-export of internal APIs: it authenticates external clients with API
keys, enforces scopes, rate limits and quotas, versions the surface, delivers
webhooks, and returns its own transformed resources through a standard envelope.

**No internal endpoint is exposed.** Public data is read through ports over other
contexts' published read side (currently Catalog), mapped to this context's own
DTOs. Cross-domain communication is event-driven; the only inbound coupling is
the webhook fan-out subscribing to internal events by name.

## Surfaces

- **Public API** — `/api/public/v1`, API-key auth. Middleware stack:
  `context → auth → ratelimit → quota → scope`. Endpoints: `status`, `scopes`,
  `foods`, `recipes` (v1). Returns `{ data, meta }` with pagination + rate/quota
  headers.
- **Developer Platform** — `/api/v1/developer`, JWT auth. Manage developer
  accounts, applications (scope-grant boundary), API keys (hashed, rotate/revoke/
  expire), webhooks (signed/retried/idempotent) and usage.

## What it owns

- **Developer / Application / ApiKey** — accounts, the scope-grant boundary, and
  credentials whose secrets are hashed (never stored in plaintext) with rotation,
  revocation and optional expiry.
- **Webhook / WebhookDelivery** — signed HMAC delivery with exponential-backoff
  retries, idempotency per `(webhook, event)`, a delivery log and secret rotation.
- **Scopes, rate limits, quotas** — `ScopeSet` (intersection never widens),
  Redis-backed limiter + quota store, standard headers.

## Layout

```
src/
  Domain/          Developer, Application, ApiKey, Webhook(+Delivery), scopes,
                   value objects, read port (Catalog), events, exceptions, ports.
  Application/     DeveloperService, ApplicationService, ApiKeyService (issue/
                   rotate/revoke/authenticate), WebhookService(+Signer),
                   RateLimitService, QuotaService, PublicResourceService,
                   ScopeRegistry, transformers + response envelope, ports.
  Infrastructure/  Eloquent models + repos, migrations (2027_03_01_*), SHA-256
                   hasher, cache rate limiter + quota store, HTTP webhook
                   dispatcher, Catalog read adapter, event subscriber (webhook
                   fan-out), retry command, service provider.
  Interface/       Middleware (auth/scope/ratelimit/quota/context), public
                   controllers (foods/recipes/meta) + developer-portal
                   controllers, routes.
tests/             Unit (scopes/keys/webhooks) + Feature (auth/scope/rate-limit).
```

## Docs

- [`docs/PUBLIC_API.md`](../../../../docs/PUBLIC_API.md)
- [`docs/DEVELOPER_PLATFORM.md`](../../../../docs/DEVELOPER_PLATFORM.md)
- [`docs/WEBHOOKS.md`](../../../../docs/WEBHOOKS.md)
- [`docs/API_SECURITY.md`](../../../../docs/API_SECURITY.md) (OWASP review)
- [`docs/SDK_GUIDE.md`](../../../../docs/SDK_GUIDE.md)
- [`docs/adr/0016-public-api-developer-platform.md`](../../../../docs/adr/0016-public-api-developer-platform.md)
