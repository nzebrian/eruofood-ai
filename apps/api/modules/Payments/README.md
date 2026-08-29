# Payments Module (`EruoFood\Payments`)

The **Payments, Wallet & Financial Services** bounded context — money movement
for the whole platform: multi-provider card/bank/wallet payments, a double-entry
transaction ledger, wallets for every account type, refunds (full & partial),
commission and settlements, vendor/driver payouts, saved (tokenised) methods and
subscription billing.

**Deliberately decoupled from the Order module.** Payments never imports order
code. Other contexts start a payment through the published
`EruoFood\Payments\Contracts\PaymentInitiator` contract (passing an *opaque*
order id), and Payments reports outcomes back only through **domain events**
(`PaymentSucceeded`, `PaymentFailed`, `RefundCompleted`, `SettlementCompleted`).
Neither side references the other's models.

## What it owns

- **Payments** (`Payment`, `Refund`) — the provider-agnostic charge lifecycle
  (guarded status, idempotency, split payments), and full/partial refunds.
- **Provider abstraction** — one `PaymentGateway` port with adapters for
  **Paystack, Flutterwave, Moniepoint, Stripe & PayPal** (the last two
  architecture-ready), plus an internal **wallet** gateway and a deterministic
  **mock** for offline tests. A `PaymentGatewayFactory` resolves them (Strategy +
  Factory) with a configurable default + fallback chain.
- **Ledger** (`LedgerEntry`, `LedgerPosting`) — an append-only **double-entry**
  ledger; every financial event posts a provably-balanced group of entries
  across cash/escrow/commission/fees/payouts/refunds accounts (tax-ready audit).
- **Wallets** (`Wallet`, `WalletTransaction`) — customer, restaurant, vendor,
  driver and platform wallets with an immutable statement; top-ups, withdrawals,
  transfers and wallet-funded payments.
- **Settlement** (`Settlement`, `Payout`) — the settlement engine aggregates a
  payee's sales, deducts commission + fees, and releases the net to their wallet
  or a bank **payout**.
- **Methods & subscriptions** (`SavedPaymentMethod`, `Subscription`) — tokenised
  PCI-safe saved cards and recurring billing (architecture-ready).
- **Webhooks** (`WebhookEventRepository`) — exactly-once, signature-verified
  inbound provider webhooks (idempotent on the provider event id).

## Folder structure

```
modules/Payments/src/
├── Domain/                    # Pure PHP — no framework
│   ├── Enum/                  # PaymentStatus/Provider/MethodType, LedgerAccount,
│   │                          #   TransactionType/Direction, Refund/Settlement/Payout status
│   ├── ValueObject/           # ProviderReference, PaymentSplit, BankAccount, CardFingerprint
│   ├── Payment/ Ledger/ Wallet/ Settlement/ Method/ Subscription/ Webhook/
│   │                          #   aggregates + repository ports
│   ├── Event/                 # PaymentSucceeded/Failed, RefundCompleted, SettlementCompleted, Wallet*
│   └── Exception/             # not-found / invalid-state / conflict / not-authorized / provider-error
├── Contracts/                 # PUBLIC: PaymentInitiator + InitiatePaymentRequest + PaymentIntent
├── Application/               # Use cases + ports
│   ├── Port/                  # PaymentGateway(+Factory), CommissionCalculator, FraudDetector,
│   │                          #   PaymentNotifier, FieldEncryptor
│   ├── Input/ DTO/            # InitiatePaymentInput; GatewayCharge/Result, WebhookPayload, FinancialReport…
│   └── Service/               # Payment, Refund, Wallet, Settlement, SavedMethod, Subscription,
│                              #   Webhook, Ledger, FinancialReport, Presenter
├── Infrastructure/            # Adapters
│   ├── Persistence/           # 10 Eloquent models + repositories, 10 migrations
│   ├── Provider/Gateway/      # Mock, Wallet, Paystack, Flutterwave, Moniepoint, Stripe, PayPal
│   ├── Provider/              # GatewayFactory (Provider Factory) + PaymentsServiceProvider
│   ├── Commission/            # ConfigCommissionCalculator (Commission Engine)
│   ├── Security/              # AllowAllFraudDetector, LaravelFieldEncryptor
│   ├── Notification/          # LoggingPaymentNotifier
│   └── Seeder/                # PaymentsSeeder (platform + demo wallets)
└── Interface/                 # HTTP (controllers, requests, webhooks, routes)
```

## Key design decisions

- **Decoupled from orders**: integrate via the `PaymentInitiator` contract in,
  domain events out. No cross-module model references (soft `order_id` only).
- **Provider abstraction + factory + strategy**: adding a provider is a new
  adapter + a factory case; the default + fallback chain is config-driven.
- **Money as integer minor units** everywhere; a **double-entry ledger** makes
  every movement balance and gives tax-ready reporting.
- **Idempotency at two layers**: payments dedupe on the caller's idempotency
  key; webhooks dedupe on the provider event id (exactly-once). Subscription
  creation binds the key to the authenticated caller before claiming it, so one
  customer's key can never reach another's record and two customers may use the
  same key value independently — and there the header is **required**, not
  optional, because a duplicate standing instruction bills every period.
- **Escrow + settlement**: customer funds land in escrow; the settlement engine
  deducts commission/fees and pays vendors out to wallet or bank.
- **PCI-aware**: only tokens + display data (brand/last4) are stored; a
  `FieldEncryptor` port wraps the framework encrypter for sensitive fields; a
  `FraudDetector` hook guards every charge.
- **Offline-testable**: the deterministic mock provider is the default when
  `APP_ENV=testing`, so the whole flow runs in CI with no network.

## Authorisation

Customers act only on their own payments/wallet/methods/subscriptions; refunds
are payer-or-admin; settlements, payouts, the transactions dashboard, financial
report and provider management are `role:admin`. Webhooks are public but
signature-verified.

## Persistence

Ten `payments_*` tables. Other contexts are referenced by ID only. Seed sample
wallets:

```
php artisan db:seed --class="EruoFood\Payments\Infrastructure\Seeder\PaymentsSeeder"
```

## Error → HTTP mapping

`PAYMENTS_RESOURCE_NOT_FOUND` → 404, `PAYMENTS_NOT_AUTHORIZED` → 403,
`PAYMENTS_INVALID_STATE` → 422 (illegal transition / insufficient balance /
over-refund / bad webhook signature), `PAYMENTS_CONFLICT` → 409 (duplicate
idempotency key / webhook), `PAYMENTS_PROVIDER_ERROR` → 502.

## Testing

- **Unit** — payment capture/idempotency/refund maths & split validation, wallet
  credit/debit/overdraw + low-balance event, the double-entry ledger balancing
  rule, the commission engine, and the offline mock gateway.
- **Feature** — initiate → capture → refund over HTTP, idempotent re-initiation,
  wallet top-up + statement, the admin financial report + a vendor settlement,
  and signature-free webhook idempotency (all offline via the mock provider).

See [docs/api/payments-endpoints.md](../../../../docs/api/payments-endpoints.md)
and [ADR-0008](../../../../docs/adr/0008-payments-platform.md).
