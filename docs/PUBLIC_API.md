# Public API

The Public API is EruoFood's **controlled external-facing surface**, mounted at
`/api/public/v1`. It is deliberately separate from the internal application APIs:
it authenticates with **API keys** (never JWT), enforces **scopes**, applies
**rate limits and quotas**, is **versioned**, and returns a **standard envelope**.
No internal endpoint is proxied — public controllers return their own transformed
resources, so the external contract is independent of internal representations.

Implemented by the `EruoFood\PublicApi` bounded context (Milestone 16).

## Base URL & versioning

```
https://<host>/api/public/v1
```

- The version is a path segment (`v1`). A future `v2` is added as a sibling
  route group; **v1 is never mutated**, so existing clients keep working.
- `GET /api/public/v1/status` returns the current version, all supported
  versions, and any deprecated ones.
- **Deprecation strategy:** a version listed in `publicapi.deprecated` still
  serves requests but every response carries `Deprecation: true` and a `Sunset`
  date header. Clients should watch for these and migrate before the sunset.

## Authentication

Send the API key as a Bearer token (or the `X-Api-Key` header):

```
Authorization: Bearer efk_live_ab12cd34.<secret>
```

Keys are minted in the [Developer Platform](DEVELOPER_PLATFORM.md). A key is
`prefix.secret`; only the prefix and a hash of the secret are stored server-side
(see [API_SECURITY.md](API_SECURITY.md)). Missing/invalid keys → `401`.

## Scopes (authorization)

Every data route requires a granted scope; a key receives **only** the scopes
explicitly granted to its application. A missing scope → `403`.

| Scope | Grants |
|---|---|
| `foods:read` | Read the food catalogue |
| `recipes:read` | Read published recipes |
| `restaurants:read` | Read restaurant/vendor profiles |
| `products:read` | Read the grocery/product catalogue |
| `nutrition:read` | Read nutrition information |
| `search:read` | Query the public search index |
| `orders:read` / `orders:write` | Read / create orders |

`GET /api/public/v1/scopes` returns the live catalogue. (Milestone 16 ships
`foods:read` and `recipes:read` as fully-wired endpoints; the remaining scopes
are first-class in the platform and reserved for their endpoints, which follow
the same read-port pattern.)

## Standard response envelope

**Single resource**

```json
{ "data": { "id": "…", "slug": "jollof-rice", "name": "Jollof Rice" }, "meta": {} }
```

**Collection** (paginated)

```json
{
  "data": [ { "…": "…" } ],
  "meta": {
    "pagination": { "page": 1, "per_page": 20, "total": 42, "last_page": 3, "has_more": true },
    "version": "v1"
  }
}
```

**Error**

```json
{ "error": { "code": "PUBLICAPI_FORBIDDEN", "message": "…", "details": {} } }
```

Error codes: `PUBLICAPI_UNAUTHENTICATED` (401), `PUBLICAPI_FORBIDDEN` (403),
`PUBLICAPI_RESOURCE_NOT_FOUND` (404), `PUBLICAPI_RATE_LIMITED` /
`PUBLICAPI_QUOTA_EXCEEDED` (429), `PUBLICAPI_INVALID_STATE` (422).

## Pagination, filtering, sorting

- **Pagination:** `?page=1&per_page=20` — `per_page` is clamped to a configured
  maximum (100). The `meta.pagination` block reports `total`, `last_page`,
  `has_more`.
- **Filtering:** `?filter[region]=south_west` — repeatable per field.
- **Search:** `?q=jollof` — full-text where the underlying resource supports it.
- **Sorting:** `?sort=name` or `?sort=-updated_at` (leading `-` = descending),
  per the resource's allowed sort keys.

## Rate limits & quotas

Every authenticated response carries:

| Header | Meaning |
|---|---|
| `X-RateLimit-Limit` | Requests allowed in the current window |
| `X-RateLimit-Remaining` | Requests left in the window |
| `X-RateLimit-Reset` | Epoch second the window resets |
| `X-Quota-Daily-Used` / `X-Quota-Daily-Limit` | Daily quota consumption |
| `X-Quota-Monthly-Used` / `X-Quota-Monthly-Limit` | Monthly quota consumption |

Exceeding a per-minute/burst limit → `429 PUBLICAPI_RATE_LIMITED` with
`Retry-After`. Exhausting a quota → `429 PUBLICAPI_QUOTA_EXCEEDED`. Counters are
Redis-backed (configurable via `publicapi.counter_store`).

Every response also carries `X-Request-Id` (correlation) and `X-Api-Version`.

## Endpoints (v1)

| Method & Path | Scope | Purpose |
|---|---|---|
| `GET /status` | — | Service status + versions (public). |
| `GET /scopes` | — | Scope catalogue (public). |
| `GET /foods` | `foods:read` | List foods (`q`, `page`, `per_page`, `sort`). |
| `GET /foods/{slug}` | `foods:read` | A food by slug. |
| `GET /recipes` | `recipes:read` | List recipes. |
| `GET /recipes/{slug}` | `recipes:read` | A recipe by slug. |

Full schema: [`packages/api-contracts/openapi.yaml`](../packages/api-contracts/openapi.yaml)
(tag **Public API**).

## Example

```bash
curl -H "Authorization: Bearer $EF_API_KEY" \
     "https://api.eruofood.example/api/public/v1/foods?q=jollof&per_page=10"
```

See [SDK_GUIDE.md](SDK_GUIDE.md) for the TypeScript, PHP and Dart SDKs, and
[WEBHOOKS.md](WEBHOOKS.md) for event delivery.
