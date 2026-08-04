# Webhooks

The Public API delivers events to developer-registered endpoints with signed
payloads, retries, and idempotency. Webhooks are managed per application in the
[Developer Platform](DEVELOPER_PLATFORM.md).

## How it works

1. An internal domain event is published (e.g. `reviews.review_published`).
2. The config `publicapi.webhooks.events` map translates it to a **public event
   name** (e.g. `review.published`).
3. Every active webhook subscribed to that event gets one delivery — **once per
   `(webhook, event)`** (idempotency), so replayed events never double-deliver.
4. Each delivery is HMAC-signed and POSTed. Non-2xx/timeout → retried with
   exponential backoff until the attempt ceiling, then marked `failed`.

The fan-out is one-way and by event name — no business module knows the webhook
system exists.

## Payload

```json
{
  "id": "9f2c…",                 // stable delivery/event id (idempotency key)
  "type": "review.published",
  "created_at": "2027-03-10T10:00:00+00:00",
  "data": { "reviewId": "…", "subjectType": "vendor", "subjectId": "…", "rating": 5 }
}
```

Delivery request headers:

| Header | Meaning |
|---|---|
| `X-EruoFood-Signature` | HMAC-SHA256 of `"{timestamp}.{body}"` |
| `X-EruoFood-Timestamp` | Unix seconds the signature was computed |
| `X-EruoFood-Delivery` | The event/delivery id (idempotency key) |

## Verifying signatures (replay-safe)

Recompute the HMAC and compare in constant time, and reject stale timestamps
(default tolerance 300s) to prevent replay:

```php
$expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $webhookSecret);
$valid = hash_equals($expected, $signatureHeader)
      && abs(time() - (int) $timestamp) <= 300;
```

```ts
import { createHmac, timingSafeEqual } from 'node:crypto';
const expected = createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');
const ok = timingSafeEqual(Buffer.from(expected), Buffer.from(sig)) && Math.abs(Date.now()/1000 - Number(ts)) <= 300;
```

Also dedupe on `X-EruoFood-Delivery` so a retried delivery you already processed
is a no-op on your side.

## Retries & backoff

- Attempt _n_ that fails is rescheduled after `backoff_base × 2^(n-1)` seconds
  (default base 30s → 30s, 60s, 120s, …).
- Up to `max_attempts` (default 6); after that the delivery is `failed` and the
  `publicapi.webhook_failed` event fires.
- A successful (2xx) delivery marks `delivered` and fires
  `publicapi.webhook_delivered`.
- The scheduled command `php artisan publicapi:dispatch-webhooks` re-attempts due
  deliveries (run every minute).

## Delivery log

`GET /api/v1/developer/applications/{appId}/webhooks/{id}/deliveries` returns
recent deliveries with status, attempts, last response code, and timestamps — for
debugging failed endpoints.

## Secret rotation

`POST …/webhooks/{id}/rotate-secret` issues a new signing secret (returned once).
Signing secrets are stored **encrypted at rest** (they must be retrievable to
sign, unlike API-key secrets which are hashed). Rotate if a secret may be
exposed; update your verifier to the new secret.

## Configuration

`config/publicapi.php` → `webhooks`: header names, `replay_tolerance_seconds`,
`max_attempts`, `backoff_base_seconds`, `timeout_seconds`, and the internal→public
`events` map.

---

## Webhook security hardening (Milestone 17)

Outbound webhooks are a classic SSRF vector: a developer registers a URL and the
platform makes a server-side request to it. The following defences apply at both
**registration** and **every delivery**.

### In-application SSRF guard (`NetworkWebhookUrlGuard`)

- **Scheme policy** — only `https` in production (`http` tolerated outside prod
  for local testing), configurable via `PUBLIC_API_WEBHOOK_SCHEMES` /
  `PUBLIC_API_WEBHOOK_ENFORCE_HTTPS`.
- **Port policy** — restricted to an allowlist (default `443,80`).
- **No credentials** — URLs containing `user:pass@` are refused.
- **Destination validation** — the host is resolved (A + AAAA) and **every**
  resolved address must be publicly routable. Blocked ranges: loopback
  (`127/8`, `::1`), private (`10/8`, `172.16/12`, `192.168/16`), link-local
  (`169.254/16` — including the cloud metadata endpoint `169.254.169.254` —
  and `fe80::/10`), CGNAT (`100.64/10`), "this host" (`0.0.0.0/8`), IPv6 ULA
  (`fc00::/7`), IPv4-mapped IPv6, and IETF/benchmarking ranges.
- **DNS-rebinding (TOCTOU) protection** — the guard re-resolves and re-validates
  immediately before **each** delivery, not only at registration, so a host
  re-pointed at an internal address after registration is still refused.
- **Optional host allowlist** — set `PUBLIC_API_WEBHOOK_ALLOWED_HOSTS` to pin
  deliveries to specific partner domains.

### Egress hardening at delivery (`HttpWebhookDispatcher`)

- **No redirects** — `withoutRedirecting()`; a 30x cannot bounce the request to
  an internal host.
- **Timeouts** — bounded connect timeout and request timeout.
- **Response size cap** — `CURLOPT_MAXFILESIZE` limits the body read back.
- **Re-validation** — the URL guard runs again before the request is sent.

### Existing protections (Milestone 16, still in force)

- **HMAC signing** of every payload (`X-EruoFood-Signature`) with a per-endpoint
  secret; **timestamp** header + replay tolerance window; **delivery id** header.
- **Safe retry** with exponential backoff up to an attempt ceiling; idempotent
  per `(webhook, event)`.
- **Secret rotation** endpoint.

### ⚠️ Infrastructure egress controls (required for GA)

Application-level DNS validation **cannot by itself** defeat a resolver that
returns a different address at connection time, nor a compromised downstream.
Production **must** pair the in-app guard with network-level egress controls:

- Route outbound webhook traffic through an **egress proxy / NAT gateway** whose
  firewall policy denies the same private/reserved ranges (belt-and-braces with
  the app guard).
- Deny the container/task network access to the **cloud metadata endpoint**
  (`169.254.169.254`) at the infrastructure layer.
- Restrict outbound egress to `:443` (and `:80` if used) only.
- Consider a dedicated, low-privilege egress path for webhook workers.

These are tracked as a GA blocker in `PUBLIC_API_GA_CHECKLIST.md` because they
are enforced outside the application and cannot be validated in this repo.
