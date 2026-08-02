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
