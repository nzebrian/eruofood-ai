# Developer Platform

The Developer Platform is the **internal, JWT-authenticated** management surface
for the Public API, mounted at `/api/v1/developer`. It is where humans create
developer accounts, applications, API keys and webhooks, and view usage. API keys
are **never** accepted here — the portal is for people, keys are for machines.

Implemented by the `EruoFood\PublicApi` bounded context; front-end at `/developer`.

## Concepts

```
User (Identity) ─1:1─ Developer ─1:N─ Application ─1:N─ ApiKey
                                        └────────────1:N─ Webhook ─1:N─ Delivery
```

- **Developer** — a thin account linking a platform user id to their apps.
  Created on first portal access (`POST /register`).
- **Application** — the **scope-grant boundary**. An application is granted a set
  of scopes; every key it issues can hold only a subset. Suspending an app is the
  kill-switch for all its keys.
- **API key** — a credential (`prefix.secret`). The secret is generated from a
  CSPRNG, shown **once**, and stored only as a hash. Keys carry their own scopes
  (⊆ the app's), may expire, and can be rotated or revoked.
- **Webhook** — an endpoint subscribing to events; see [WEBHOOKS.md](WEBHOOKS.md).

## Endpoints

Base: `/api/v1/developer` (all require a valid JWT).

| Method & Path | Purpose |
|---|---|
| `POST /register` | Create/return the developer account (`name`, `email`). |
| `GET /me` | The developer account. |
| `GET /applications` | List the developer's applications. |
| `POST /applications` | Create an application (`name`, `description`, `scopes`). |
| `GET /applications/{id}` | An application. |
| `PUT /applications/{id}/scopes` | Replace the application's granted scopes. |
| `POST /applications/{id}/suspend` | Suspend an application (revokes all keys' access). |
| `GET /applications/{appId}/keys` | List keys (prefixes only — never secrets). |
| `POST /applications/{appId}/keys` | Issue a key → returns the plaintext **once**. |
| `POST /keys/{keyId}/rotate` | Revoke + reissue (returns new plaintext once). |
| `DELETE /keys/{keyId}` | Revoke a key. |
| `GET /applications/{appId}/webhooks` | List webhooks. |
| `POST /applications/{appId}/webhooks` | Create a webhook (`url`, `events`) → secret once. |
| `PUT /applications/{appId}/webhooks/{id}` | Update url/events. |
| `POST /applications/{appId}/webhooks/{id}/rotate-secret` | Rotate the signing secret. |
| `DELETE /applications/{appId}/webhooks/{id}` | Disable a webhook. |
| `GET /applications/{appId}/webhooks/{id}/deliveries` | Delivery log. |
| `GET /applications/{appId}/usage` | Quota consumption + rate-limit config. |

Ownership is enforced on every call — a developer can only touch their own
applications, keys and webhooks (`403` otherwise).

## Credential lifecycle

1. **Issue** — `POST …/keys` returns `{ prefix, key, notice, … }`. Copy `key`
   now; only the prefix is retrievable afterwards.
2. **Use** — send `Authorization: Bearer <key>` against `/api/public/v1`.
3. **Rotate** — `POST /keys/{id}/rotate` revokes the old key and mints a new one;
   update your client, then the old key stops working immediately.
4. **Revoke** — `DELETE /keys/{id}` disables a key at once.
5. **Expiry** — pass `ttl_days` at issue for an expiring credential (e.g. CI
   tokens); expired keys fail authentication automatically.

## Usage statistics

`GET …/usage` returns current daily/monthly quota consumption and the per-minute
rate limit. The portal surfaces this per application. Per-request analytics
(counts, latency, error rates) flow to the Analytics context via the
`publicapi.request_served` event (see [API_SECURITY.md](API_SECURITY.md) → Analytics).

## Portal (web)

The React portal at `/developer` lets a signed-in user manage applications,
issue/revoke keys (with one-time secret reveal), configure webhooks, and view
usage. It calls only the `/api/v1/developer` endpoints above.
