# ADR-0008: Payments — a decoupled context with a provider abstraction and a double-entry ledger

- **Status:** Accepted
- **Date:** 2026-07-27
- **Deciders:** Engineering, Product, Finance

## Context

Milestone 8 adds Payments, Wallet & Financial Services: multi-provider payments
(Paystack, Flutterwave, Moniepoint, Stripe, PayPal), wallets for every account
type, a transaction ledger, refunds, commission, settlements and payouts, saved
methods and subscriptions. Money must stay correct across many providers and
account types, and the design must not entangle payments with the order flow —
the explicit requirement is that Payments be its own domain, communicating with
other modules through **domain events and application services** only.

## Decision

- **A standalone `EruoFood\Payments` context, decoupled from orders.** Payments
  never imports Order/Commerce/Marketplace code. Other contexts start a payment
  through the published `PaymentInitiator` **contract**, passing an *opaque*
  order id; Payments reports outcomes back through **domain events**
  (`PaymentSucceeded`, `PaymentFailed`, `RefundCompleted`,
  `SettlementCompleted`). The dependency is one-way and contract-only, so either
  side can change independently.
- **A provider abstraction layer (Strategy) resolved by a Factory.** One
  `PaymentGateway` port defines initialize/verify/refund/transfer/parseWebhook;
  each provider is an adapter. A `PaymentGatewayFactory` builds them from config
  with a **default + fallback chain**. Adding a provider is a new adapter plus a
  factory case — no caller changes. Stripe and PayPal ship as architecture-ready
  adapters (disabled by default); an internal **wallet** gateway and a
  deterministic **mock** (the testing default) complete the set.
- **Money as integer minor units, on a double-entry ledger.** Every financial
  event posts a **balanced** group of `LedgerEntry` rows across
  cash/escrow/commission/fees/payouts/refunds accounts. The domain enforces that
  debits and credits net to zero before posting, giving a tamper-evident,
  tax-ready audit trail independent of any provider.
- **Idempotency at both edges.** Outbound: payments dedupe on the caller's
  idempotency key (safe retries). Inbound: webhooks are signature-verified and
  deduped on the provider event id, so a redelivered event is applied
  exactly-once.
- **Escrow + a settlement engine.** Customer funds are captured into escrow; the
  settlement engine aggregates a payee's sales, applies the **commission engine**
  (a port — basis-point rate + flat fee), and releases the net to the payee's
  wallet or a bank **payout** via the provider. Split payments route shares to
  vendors/drivers at capture time.
- **PCI-aware by construction.** Only provider **tokens** plus non-sensitive
  display data (brand, last four, expiry) are stored — never PAN/CVV. A
  `FieldEncryptor` port wraps the framework encrypter for sensitive at-rest
  fields, and a `FraudDetector` hook is consulted before every charge (default
  allow, replaceable by a rules/ML engine).

## Consequences

- **Positive:** payments and orders evolve independently; providers are
  swappable and testable; the ledger guarantees money balances and supports
  finance/tax reporting; retries and webhook redeliveries are safe; escrow +
  settlement give a clean marketplace payout model; the whole flow runs offline
  in CI via the mock provider.
- **Negative / trade-offs:** the real provider adapters (Paystack/Flutterwave/
  Moniepoint/Stripe/PayPal) are structured against documented request/response
  shapes but only the mock is exercised in CI — live-provider contract tests
  belong in a sandbox integration suite. The commission engine is a single
  basis-point + flat model for now, and fraud detection is a stub hook. Cross-
  wallet transfers are application-level atomic (two saves in a DB transaction),
  not a single aggregate — acceptable because the ledger is the source of truth.
- **Follow-ups:** live-provider sandbox contract tests; a real fraud engine
  behind the hook; per-vendor commission tiers; scheduled subscription charging
  and settlement cadence jobs; chargeback ingestion via provider webhooks.

## Alternatives considered

- **Fold payments into the Order/Commerce module** — rejected outright: it
  violates the decoupling requirement and would couple money correctness to
  order state.
- **A single provider (Paystack) with no abstraction** — faster now but locks in
  one PSP; the port/factory cost is small and buys provider independence and
  offline testing.
- **A simple balances table instead of a double-entry ledger** — rejected;
  balances alone are not auditable or reconcilable. The ledger is the financial
  source of truth and wallet balances are a projection of it.
- **Floating-point money** — rejected; integer minor units avoid rounding drift.
