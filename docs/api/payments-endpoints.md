# Payments, Wallet & Financial Services — API Endpoints

Base URL: `/api/v1`. All payment paths are under **`/payments`**. Provider
webhooks are public (signed by the provider); everything else needs a bearer
token; settlements, payouts, the transactions dashboard, financial report and
provider management need the `admin` role. Money is in **integer minor units**
(kobo). Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Payments (customer)

| Method & Path | Purpose |
|---|---|
| `POST /payments/payments` | Initiate a payment (`amount_minor`, `customer_email`, `order_id?`, `method_type?`, `provider?`, `idempotency_key?`, `splits?`). Idempotent on the key. |
| `GET /payments/payments` | The caller's payment history. |
| `GET /payments/payments/{id}` | Payment detail (payer or admin). |
| `POST /payments/payments/{id}/verify` | Verify with the provider and capture if confirmed. |
| `POST /payments/payments/{id}/cancel` | Cancel a pending payment. |

## Refunds (customer / admin)

| Method & Path | Purpose |
|---|---|
| `POST /payments/refunds` | Refund a captured payment — full, or partial with `amount_minor` (`payment_id`, `reason`). |
| `GET /payments/payments/{paymentId}/refunds` | Refunds for a payment. |

## Wallet (customer)

| Method & Path | Purpose |
|---|---|
| `GET /payments/wallet` | The caller's wallet balance. |
| `GET /payments/wallet/statement` | The wallet statement (paginated). |
| `POST /payments/wallet/topup` | Top up (takes a payment, credits on capture). |
| `POST /payments/wallet/transfer` | Transfer to another user's wallet (`to_user_id`, `amount_minor`, `note?`). |

## Saved methods & subscriptions (customer)

| Method & Path | Purpose |
|---|---|
| `GET /payments/methods` · `POST /payments/methods` | List / save a tokenised method (PCI-safe: token + brand + last4). |
| `POST /payments/methods/{id}/default` · `DELETE /payments/methods/{id}` | Make default / delete. |
| `GET /payments/subscriptions` · `POST /payments/subscriptions` | List / start a subscription (`plan`, `amount_minor`, `interval`). **`Idempotency-Key` is required** on the POST — unlike elsewhere, where it is optional — because a duplicate subscription bills every period rather than once. The same key and body replays the original subscription (200 rather than 201), the same key with a different body is refused, and the key is scoped to the caller, so two users may use the same value independently. |
| `POST /payments/subscriptions/{id}/cancel` | Cancel a subscription. |

## Webhooks (public — provider-signed)

| Method & Path | Purpose |
|---|---|
| `POST /payments/webhooks/{provider}` | Receive a provider webhook (`paystack`\|`flutterwave`\|`moniepoint`\|`stripe`\|`paypal`\|`mock`). Signature-verified and deduped on the event id (exactly-once). |

## Admin (role: admin)

| Method & Path | Purpose |
|---|---|
| `GET /payments/admin/payments` | Transactions dashboard — all payments (`status` filter). |
| `GET /payments/admin/refunds` | All refunds. |
| `GET /payments/admin/report` | Financial report (gross, commission, fees, refunded, net). |
| `GET /payments/admin/providers` | Enabled providers (default first). |
| `POST /payments/admin/settlements` | Run a settlement for a payee (`payee_type`, `payee_id`, `gross_minor`, period, `bank?`). Pays to wallet, or bank when `bank` is given. |
| `GET /payments/admin/settlements` | Settlement runs. |
| `GET /payments/admin/payouts` | Payouts. |

## Cross-module integration (no HTTP)

Other contexts start a payment through the published contract
`EruoFood\Payments\Contracts\PaymentInitiator::initiate(InitiatePaymentRequest)`
— passing an **opaque** order id — and react to the outcome via domain events
(`PaymentSucceeded`, `PaymentFailed`, `RefundCompleted`, `SettlementCompleted`).
Payments never calls the Order module back directly.

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `PAYMENTS_RESOURCE_NOT_FOUND` | 404 | Payment/wallet/refund/settlement/payout missing. |
| `PAYMENTS_NOT_AUTHORIZED` | 403 | Not the payer / wallet owner. |
| `PAYMENTS_INVALID_STATE` | 422 | Illegal transition, insufficient balance, over-refund, split mismatch, bad webhook signature. |
| `PAYMENTS_CONFLICT` | 409 | Duplicate idempotency key or webhook. |
| `PAYMENTS_PROVIDER_ERROR` | 502 | Provider unavailable / returned an error. |
| `IDEMPOTENCY_IN_FLIGHT` | 409 | An earlier request with this `Idempotency-Key` is still running. Nothing was changed; retry shortly. |
| `IDEMPOTENCY_KEY_REUSED` | 422 | This `Idempotency-Key` was already spent on a different body. Use a fresh key. |
| `INVALID_ARGUMENT` | 422 | Malformed input — including an `Idempotency-Key` that is missing where required, blank, or longer than 255 characters. |
