# M27 — Financial Settlement, Merchant Payout & Reconciliation

**Plan only. No implementation.**
Baseline: `main` at `fc412dcd9721fd9d3fb48f4b81f804819bdf014f`.

---

## 1. Current architecture audit

The instruction not to assume absence from filenames was well placed. **A settlement
implementation already exists** — `Settlement` and `Payout` aggregates, repositories,
Eloquent models, two migrations, a `SettlementService`, an admin controller and routes,
all shipped in Milestone 8. M27 is therefore **not greenfield**: it is the milestone that
makes an existing, structurally unsafe money-moving path production-grade.

### 1.1 What exists and is production-ready

| Component | Location | Assessment |
|---|---|---|
| **Double-entry ledger** | `Payments/Domain/Ledger/*` | **Production-ready.** `LedgerPosting::balanced()` throws `PaymentsInvalidState` when postings do not sum to zero. Nine accounts (`Cash`, `Escrow`, `Commission`, `Fees`, `Payouts`, `Refunds`, three wallets). |
| **Ledger immutability** | `2027_02_01_000004_protect_ledger_append_only.php` | **Production-ready.** PostgreSQL trigger enforcing append-only. |
| **Wallet balance guard** | `2027_02_01_000003_add_wallet_balance_guard.php` + `2027_02_01_000002_add_version_to_payments_wallets.php` | **Production-ready.** DB constraint plus optimistic `version`. |
| **Webhook exactly-once** | `WebhookService`, `payments_webhook_events` | **Production-ready.** Unique constraint on the provider event id; duplicate delivery loses the race. Covered by `WebhookExactlyOnceTest`. |
| **Gateway abstraction** | `Application/Port/PaymentGateway`, `PaymentGatewayFactory`, 8 adapters | **Production-ready as an abstraction.** Flutterwave, Paystack, Stripe, PayPal, Moniepoint, Mock, Wallet, plus `AbstractHttpGateway`. **M27 must not create a second gateway abstraction.** |
| **Idempotency store** | `Shared/Domain/Idempotency/*` | **Production-ready.** Unique-index arbitration, request fingerprint, TTL, crash reclaim. |
| **TransactionManager / ConcurrencyConflict** | `Shared/Domain` | **Production-ready.** Deadlock retry, optimistic version conflicts. |
| **M23 concurrency harness** | `scripts/financial_concurrency_validation.php` + `_worker.php` | **Production-ready.** Real OS processes on real PostgreSQL, 23 checks, 7 scenarios (`wallet-debit`, `wallet-transfer`, `refund`, `commerce-checkout`, `redeem`, `webhook`, `idempotent`). **Extend, do not replace.** |
| **Cross-cutting foundation (PR #19)** | `Shared/Domain/{Time,Correlation,Flag,Schedule,Lifecycle,Reconciliation,DataLifecycle,Risk}` | **Production-ready.** UTC clock, correlation IDs, flag registry, scheduler seam, `ServerPhase`, `ReconcilableOperation`, retention registry, risk seam. |
| **Admin RBAC + audit** | `Admin/Domain/Rbac/Permission`, `admin_audit_entries` | **Production-ready** mechanism. Append-only audit enforced by trigger. |

### 1.2 What exists but is NOT production-ready

These are the findings that define M27's real scope.

**F1 — Merchant payable is not derived; it is supplied by the caller.**
`SettlementService::settle()` takes `int $grossMinor` as a parameter, and
`SettlementAdminController` validates it as `['required','integer','min:1']` straight
from the request body. An operator types a number and the platform pays it. This
violates *"merchant payable balance must be derived from authoritative financial
records"* outright. There is no query anywhere that derives what a merchant is owed.

**F2 — Settlement is not idempotent.**
`settle()` accepts no idempotency key and the `payments_settlements` table has no unique
constraint beyond its primary key. Two clicks, two retries, or two workers produce two
settlements and two bank transfers for the same period.

**F3 — The provider transfer has no crash-safe outcome.**
`settle()` is deliberately three phases, and the comment says so: phase 2 calls
`$gateway->transfer(...)` **outside any transaction**. If the process dies after the
provider accepts the transfer but before phase 3 commits, **money has left the platform
with no payout row, no ledger entry and no settlement completion**. There is no state
that represents this, and nothing that would ever discover it.

**F4 — `GatewayResult` cannot express an unknown outcome.**
`public bool $success` plus a free-text `status` string. A timeout, a 5xx, or a dropped
connection is indistinguishable from a decline. Every "unknown outcome must reconcile
before retrying" requirement is unimplementable against this DTO as it stands.

**F5 — A read permission authorises money movement.**
`routes.php` guards the entire admin group — including `POST admin/settlements`, which
transfers to a bank account — with `permission:finance.read`. `FinanceManager`,
`SuperAdmin`, `CustomerSupport` and others hold `finance.read`. **This is the single most
serious finding in the audit.**

**F6 — No reconciliation of any kind exists.**
Nothing compares provider state to internal state, ledger to wallet, or payable to
settled. `ReconcilableOperation` (PR #19) covers client request recovery, not financial
reconciliation — a different problem with a different shape.

**F7 — Escrow accumulates and is never released by order completion.**
`LedgerService::recordCapture()` credits `Escrow`; `recordSettlement()` debits it. But no
Marketplace or Commerce code path touches escrow — grep returns nothing. Escrow grows
without bound and nothing ties a merchant's payable to *delivered* orders.

**F8 — Settlement lifecycle is too small.**
`SettlementStatus` is `Pending → Processing → Completed | Failed`. No unknown, no
reconciliation-required, no reversed, no cancelled.

**F9 — No optimistic locking on settlement or payout.**
Neither table has a `version` column, unlike `payments_wallets` which does.

**F10 — Payments does not use `NotificationService`.**
`PaymentNotifier` is bound to `LoggingPaymentNotifier`, which writes log lines. Merchants
are told nothing about their money.

**F11 — Currency is assumed, not enforced.**
`settle()` builds `new Money($grossMinor, $this->currency)` from config. Both tables
default `currency` to `'NGN'`. Nothing rejects a cross-currency settlement.

**F12 — No audit on settlement actions.** No settlement or payout operation writes an
`admin_audit_entries` row.

### 1.3 Ownership boundaries

| Concern | Owner | Note |
|---|---|---|
| Ledger, wallets, payments, refunds, gateways, webhooks | **Payments (existing)** | M27 extends; must not fork. |
| Merchant payable derivation, settlement runs, payout attempts, reconciliation | **M27** | New capability, inside the Payments context. |
| Order/delivery completion facts | **Marketplace / Commerce** | M27 *consumes* via contract or event. Must never infer financial truth from order status directly — see §4. |
| Merchant/vendor identity and bank details | **Marketplace (vendors)** | M27 reads via a port; does not own. |
| RBAC, audit sink | **Admin** | M27 adds permissions, uses the existing audit. |
| Notifications | **Notifications** | M27 replaces the logging stub with the real service. |

**Recommendation: M27 stays inside the `Payments` bounded context** rather than creating
a new `Settlement` module. The ledger, wallets, gateways and escrow all live here, and a
separate context would either duplicate them or reach into them — the exact coupling this
codebase avoids. M27 adds a `Settlement` sub-domain within Payments.

---

## 2. Components M27 will reuse (never duplicate)

`LedgerService` · `LedgerPosting::balanced()` · append-only trigger · `PaymentGateway` +
`PaymentGatewayFactory` + existing adapters · `WebhookService` dedup · `IdempotencyStore`
· `TransactionManager` · `ConcurrencyConflict` · `Clock` (UTC) · `CorrelationContext` ·
`FlagRegistry`/`FlagEvaluator` · `ScheduleRegistry` · `ServerPhase` · `RetentionRegistry`
· `RiskEvaluator` · `NotificationService` · Admin `Permission` + audit · M23 concurrency
harness · `Money`.

---

## 3. Dependency diagram

```
                    ┌──────────────────────────────────────┐
                    │  Shared (UTC clock, idempotency,     │
                    │  correlation, flags, scheduler,      │
                    │  transactions, ServerPhase, risk)    │
                    └──────────────┬───────────────────────┘
                                   │ (everything depends inward)
   ┌───────────────┐   events   ┌──┴─────────────────────────────┐
   │ Marketplace   │───────────►│  Payments  (M27 lives here)    │
   │ Commerce      │  Order/    │                                │
   │ (orders,      │  Delivery  │  ┌──────────────────────────┐  │
   │  vendors)     │  completed │  │ Settlement sub-domain    │  │
   └───────────────┘            │  │  PayableAccrual          │  │
           ▲                    │  │  SettlementRun           │  │
           │ port (read only)   │  │  PayoutAttempt           │  │
           │ vendor + bank      │  │  ReconciliationCase      │  │
           └────────────────────┤  └───────────┬──────────────┘  │
                                │              │ uses            │
                                │  ┌───────────▼──────────────┐  │
                                │  │ Ledger · Wallets ·       │  │
                                │  │ Gateways · Webhooks      │  │
                                │  └──────────────────────────┘  │
                                └────────┬───────────────────────┘
                                         │ published contracts
                        ┌────────────────┼─────────────────┐
                        ▼                ▼                 ▼
                 ┌────────────┐   ┌────────────┐   ┌──────────────┐
                 │   Admin    │   │Notifications│  │   Dispatch   │
                 │ RBAC/audit │   │  (reuse)    │  │ (no coupling)│
                 └────────────┘   └────────────┘   └──────────────┘
```

**No cross-context FKs.** Order and vendor references are soft UUIDs. Dispatch has **no**
settlement coupling in M27.

---

## 4. When money changes meaning

The critical design rule: **settlement reads the ledger, never the order status.** An
order row says what a kitchen did; the ledger says what the money did. F1 exists because
that line was never drawn.

| Stage | Trigger (authoritative) | Ledger effect | Merchant payable |
|---|---|---|---|
| **Customer payment** | Provider webhook verified (`WebhookService`) | `Cash` ↑, `Escrow` ↑ net, `Commission` ↑, `Fees` ↑ | none yet |
| **Platform-held funds** | same | net sits in `Escrow` | none yet |
| **Merchant payable accrues** | **`OrderSettled` domain event** raised when Marketplace/Commerce marks the order financially final *and* payment is `Confirmed` | new: `PayableAccrual` row, ledger `Escrow` → `MerchantPayable` | **+net** |
| **Commission** | at capture, deterministically | `Commission` credited at capture, never recomputed later | — |
| **Settlement run** | scheduled or manual, over accrued payables only | `MerchantPayable` → `Payouts` (or wallet) | **−settled** |
| **Payout** | provider transfer confirmed | `Payouts` → `Cash` (outbound) | unchanged |
| **Refund** | refund confirmed | `Refunds` ↑, `Escrow`/`MerchantPayable` ↓ | **−refund**, floored at 0 |
| **Reversal** | provider reversal / recall | compensating entries, never edits | **+reversed** |

**New ledger account required: `MerchantPayable`.** Today `Escrow` conflates "money we
hold" with "money we owe a specific merchant". Payable must be per-merchant and derivable
by summing accruals minus settlements.

**Decision — commission timing:** commission is computed and posted **at capture**, not at
settlement. This makes it deterministic and auditable (the rate at the time of sale), and
means a later commission-rate change cannot retroactively alter historical payables.

---

## 5. Domain model

```
Payments/Domain/Settlement/
  PayableAccrual        entity   merchant owes-line, one per settled order
  MerchantPayable       VO       derived balance (never stored as truth)
  SettlementRun         aggregate  one settlement of one merchant for one window
  SettlementLine        entity   accrual ↔ run link (append-only)
  PayoutAttempt         aggregate  one provider transfer attempt
  ReconciliationCase    aggregate  a discrepancy requiring a human
  SettlementReference   VO       deterministic, unique, provider-safe
  Enum/ SettlementRunState, PayoutAttemptState, ReconciliationState,
        DiscrepancyKind, PayoutFailureReason
```

`Settlement` and `Payout` (the M22 aggregates) are **retained**. `SettlementRun` and
`PayoutAttempt` supersede them for new flows; the old ones become read-only history. This
mirrors M26's decision to keep `DeliveryStatus::Assigned` as a legacy alias rather than
rewrite shipped data.

---

## 6. State machines

### 6.1 SettlementRun

```
                  ┌──────────► CANCELLED (only from DRAFT/PENDING)
                  │
DRAFT ──► PENDING ──► PROCESSING ──► SUCCEEDED
                          │  │
                          │  └──────► UNKNOWN ──► RECONCILING ──► SUCCEEDED
                          │                            │            FAILED
                          │                            └──────────► RECONCILIATION_REQUIRED
                          └──────► FAILED ──► PENDING (retry)
SUCCEEDED ──► REVERSED  (compensating only; never rewrites)
```

Derived from the architecture, not adopted blindly:

- **DRAFT** is required because a run must be *computed and reviewable* before it moves
  money — the answer to F1. A draft names its accruals and totals; approving it is a
  separate, audited act.
- **UNKNOWN** is required by F3/F4: the transfer was dispatched and we did not hear back.
  It is **not** a failure and **must not** be retried directly.
- **RECONCILING** and **RECONCILIATION_REQUIRED** are distinct: the first is the system
  querying the provider, the second is a human's problem. Silent auto-correction is
  forbidden.
- **REVERSED** is terminal and additive. Ledger entries are append-only; a reversal posts
  compensating entries.
- No transition out of `SUCCEEDED` except `REVERSED`; none out of `FAILED` except a new
  `PENDING` attempt; none at all out of `CANCELLED` or `REVERSED`.

`SettlementRunState` implements `ServerAuthoritative`, projecting onto `ServerPhase`.
`PROCESSING` and `UNKNOWN` both map to `ServerPhase::Processing`, which
`isSafelyRetryable()` already refuses — reusing a guarantee PR #19 already tests.

### 6.2 PayoutAttempt

```
CREATED ──► SUBMITTED ──► CONFIRMED
               │  │
               │  └──────► UNKNOWN ──► RECONCILING ──► CONFIRMED | FAILED | RECONCILIATION_REQUIRED
               └──────► REJECTED (provider declined — terminal, retryable as a NEW attempt)
```

A retry is always a **new attempt row** with a new idempotency key, never a mutation.
Attempts are append-only, so "how many times did we try to pay this merchant" is
answerable for ever.

### 6.3 ReconciliationCase

```
OPEN ──► INVESTIGATING ──► RESOLVED_MATCHED
                       ├─► RESOLVED_ADJUSTED   (requires compensating ledger entry + approval)
                       └─► ESCALATED
```

`RESOLVED_ADJUSTED` **requires** a linked compensating ledger posting and a named
approver. There is no path that closes a case by editing a financial record.

---

## 7. Financial invariants and how each is enforced

| # | Invariant | Enforcement |
|---|---|---|
| 1 | No money created or destroyed | `LedgerPosting::balanced()` (exists) + every settlement path posts through it |
| 2 | Balanced double-entry | as above; unit + concurrency assertions |
| 3 | Settlement idempotent | `IdempotencyStore` scope `payments.settlement.run` + **unique index** on `(merchant_id, window_start, window_end)` where state ∉ terminal-failed |
| 4 | Duplicate webhooks create no money | existing unique event-id constraint (verified in place) |
| 5 | Duplicate settlement requests create no duplicate payouts | unique settlement reference + partial unique index on live runs per merchant |
| 6 | N workers → exactly one outcome | `SELECT FOR UPDATE` on merchant payable row, ordered merchant-first; optimistic `version`; partial unique index as last line |
| 7 | Failed settlement safely retryable | `FAILED → PENDING` only; new attempt row, new key |
| 8 | Unknown must reconcile before retry | `UNKNOWN` has **no** transition to `PENDING`; only via `RECONCILING` |
| 9 | Refunds ≤ refundable capacity | existing refund guard + payable floor at zero, DB `CHECK (payable_minor >= 0)` |
| 10 | Payable derived from authoritative records | `MerchantPayable` computed from `PayableAccrual` − `SettlementLine`; **never** a request field. Fixes F1 |
| 11 | Commission deterministic and auditable | posted at capture with the rate snapshot stored on the accrual |
| 12 | Traceable correlation/idempotency reference | `correlation_id` (server-generated, PR #19) on every run, attempt and case |
| 13 | No floating point | `Money` minor units, `bigint` columns (already the convention) |
| 14 | Currency never silently converted | `CHECK` constraint that accrual, run and payout currencies match; explicit `CurrencyMismatch` exception |
| 15 | Cross-currency explicitly rejected | M27 **rejects**. No FX flow. Documented as M28+ |
| 16 | Every transition auditable | audit entry per state change on runs, attempts, cases |
| 17 | Append-only where appropriate | ledger (trigger exists), accruals, settlement lines, payout attempts |
| 18 | Discrepancies surface, never auto-corrected | `RECONCILIATION_REQUIRED` is terminal without human action; `RESOLVED_ADJUSTED` needs approver + compensating entry |

---

## 8. Database schema proposal

All additive. No destructive change to M22–M26 tables.

**`payments_payable_accruals`** (append-only)
`id` · `merchant_id` · `order_id` · `payment_id` · `currency(3)` ·
`gross_minor` `commission_minor` `fee_minor` `net_minor` (bigint) ·
`commission_rate_snapshot` · `accrued_at` · `correlation_id` · `created_at`
— **unique `(order_id)`** so one order accrues once, ever. Append-only trigger.

**`payments_settlement_runs`**
`id` · `merchant_id` · `currency` · `window_start` `window_end` ·
`gross_minor` `commission_minor` `fee_minor` `net_minor` ·
`state` · `idempotency_key` · `settlement_reference` · `correlation_id` ·
`approved_by` `approved_at` · `version` · timestamps
— **unique `(idempotency_key)`**, **unique `(settlement_reference)`**,
**partial unique `(merchant_id, window_start, window_end)` where state NOT IN
('cancelled','failed')** — the last-line guarantee that survives a forgotten lock.
`CHECK (net_minor = gross_minor - commission_minor - fee_minor)`.

**`payments_settlement_lines`** (append-only)
`id` · `settlement_run_id` · `accrual_id` · `net_minor`
— **unique `(accrual_id)`**: an accrual can be settled exactly once, platform-wide. This
single constraint is what makes double-payment structurally impossible.

**`payments_payout_attempts`** (append-only)
`id` · `settlement_run_id` · `attempt_no` · `provider` · `provider_reference` ·
`amount_minor` `currency` · `state` · `failure_reason` · `idempotency_key` ·
`correlation_id` · `submitted_at` `settled_at` · `raw_response_digest`
— **unique `(settlement_run_id, attempt_no)`**, **unique `(idempotency_key)`**,
**unique `(provider, provider_reference)` where provider_reference IS NOT NULL**.
Stores a *digest*, never the raw provider payload (§13).

**`payments_reconciliation_cases`**
`id` · `kind` · `subject_type` `subject_id` · `expected_minor` `observed_minor` ·
`currency` · `state` · `opened_at` · `resolved_at` · `resolved_by` ·
`resolution_note` · `compensating_posting_id` · `correlation_id` · `version`
— `CHECK`: `state='resolved_adjusted'` requires both `resolved_by` and
`compensating_posting_id` NOT NULL.

**`payments_merchant_payable_snapshots`** (optional, read-model only)
Never authoritative; rebuildable from accruals and lines. Included only if operator
queries prove too slow, and reconciliation compares it against the derived truth.

**New `LedgerAccount::MerchantPayable`.**
**New `version` columns** on the legacy `payments_settlements` / `payments_payouts` if
those paths remain writable (recommendation: freeze them read-only instead).

---

## 9. Provider integration architecture

**Reuse `PaymentGateway`. Do not create a second abstraction.** Two changes:

1. **`GatewayResult` gains an explicit outcome enum** — `Succeeded | Processing | Failed |
   Unknown` — replacing `bool $success` at call sites that move money. `Unknown` is
   returned on timeout, connection failure, or any 5xx. This is the DTO change that makes
   F3/F4 fixable; keeping `bool $success` for backwards compatibility is acceptable, but
   settlement paths must read the enum.
2. **A new port `PayoutGateway`** (or an extension of `transfer()`) adding
   `fetchTransferStatus(providerReference)` — reconciliation cannot exist without a way
   to ask the provider what actually happened.

Provider vocabulary stays inside adapters. `FlutterwaveGateway` and `PaystackGateway`
already do this; new adapters follow. Provider credentials are read from config through
existing bindings and never logged, never returned, never stored on an attempt row.

---

## 10. Settlement and payout lifecycle

1. **Accrue** — on `OrderSettled` (order financially final ∧ payment confirmed), insert
   one `PayableAccrual`, post `Escrow → MerchantPayable`. Idempotent on `order_id`.
2. **Compute** — scheduled or manual: select unsettled accruals for a merchant within a
   window, build a **DRAFT** run. Reads only; changes nothing.
3. **Approve** — an operator with `finance.settle` approves. Audited. Below a configured
   minimum payout the run stays draft and rolls into the next window.
4. **Reserve** — atomically: lock merchant payable row, re-verify accruals unsettled,
   insert `SettlementLine` rows (unique on `accrual_id` — the collision point),
   `PENDING → PROCESSING`.
5. **Pay** — create `PayoutAttempt`, call the gateway with the attempt's idempotency key.
6. **Record** — `Succeeded` → `CONFIRMED` + ledger `MerchantPayable → Payouts`;
   `Failed` → `REJECTED`, run `FAILED`, retryable as a new attempt;
   **`Unknown` → `UNKNOWN`, and stop.**
7. **Reconcile** — the sweep asks the provider; matched → `CONFIRMED`; contradicted →
   `ReconciliationCase`.

Steps 4 and 6 are transactional. Step 5 is deliberately outside a transaction — a network
call must never hold a row lock — which is exactly why step 6 must handle `Unknown`
rather than assuming.

---

## 11. Reconciliation architecture

Four reconcilers, each scheduled, each flag-gated, **each read-only by default**:

| Reconciler | Compares | Discrepancy → |
|---|---|---|
| **Provider ↔ platform** | provider transfer status vs `PayoutAttempt` | `PAYOUT_STATE_MISMATCH` |
| **Ledger ↔ wallet** | `LedgerService::balanceOf()` vs wallet balances | `LEDGER_WALLET_DRIFT` |
| **Payable ↔ settled** | Σ accruals − Σ lines vs derived payable | `PAYABLE_DRIFT` |
| **Payment ↔ accrual** | confirmed payments with no accrual, or vice versa | `MISSING_ACCRUAL` / `ORPHAN_ACCRUAL` |

- **Automatic**: only *closing* a case as `RESOLVED_MATCHED` when provider and platform
  agree. Nothing else may be automatic.
- **Manual**: every non-matching case. `RESOLVED_ADJUSTED` requires an approver **and** a
  compensating ledger posting; the DB `CHECK` makes a note-only resolution impossible.
- **Never silently fix.** There is no code path that edits a financial record to make a
  discrepancy disappear. The `UNKNOWN → RECONCILING → RECONCILIATION_REQUIRED` chain
  terminates at a human by design.

---

## 12. Concurrency strategy

Four layers, mirroring M23/M26:

1. `SELECT FOR UPDATE` on the merchant payable row — **always merchant-first**, so lock
   ordering cannot deadlock across concurrent runs.
2. Re-read accrual state *inside* the lock (the M26 "re-check inside the lock" rule).
3. Optimistic `version` on runs, attempts and cases.
4. **Partial unique indexes as the last line** — `settlement_lines(accrual_id)` and the
   live-run index. These hold even if a future refactor forgets the lock.

**Extend `scripts/financial_concurrency_validation.php` and `_worker.php`** with new
scenarios beside the existing seven. Required, each asserting *exactly one* valid
outcome:

1. Two workers settling the same merchant payable
2. Duplicate settlement requests (same idempotency key)
3. Settlement racing refund
4. Settlement racing order cancellation
5. Duplicate provider webhook during settlement
6. Settlement retry racing the original attempt
7. Concurrent payouts to the same merchant
8. Reconciliation racing settlement
9. Unknown payout outcome followed by reconciliation
10. Ledger integrity after all of the above (sum = 0, payable ≥ 0, no accrual settled twice)

Target: **33/33 passed, 0 failed** (existing 23 + 10). SQLite keeps the fast path; only
PostgreSQL proves the guarantees.

---

## 13. Security / RBAC

**Fixes F5.** New permissions, separating reading from moving money:

| Permission | Grants | Roles |
|---|---|---|
| `finance.read` *(exists)* | view settlements, payables, attempts | FinanceManager, SuperAdmin, Admin, CustomerSupport |
| **`finance.settle`** | approve and execute a settlement run | FinanceManager, SuperAdmin |
| **`finance.payout`** | initiate/retry a payout attempt | FinanceManager, SuperAdmin |
| **`finance.reconcile`** | open/investigate cases | FinanceManager, SuperAdmin |
| **`finance.adjust`** | `RESOLVED_ADJUSTED` with compensating entry | **SuperAdmin only** |
| **`finance.reverse`** | reverse a succeeded settlement | **SuperAdmin only** |

`POST admin/settlements` and every new money-moving route move off `finance.read`
immediately — this is a **prerequisite**, not a later step (see §20 step 1).

Also: merchant-facing endpoints scope every query to the authenticated merchant (IDOR);
approval and execution are separate acts by separate permissions; webhook signature
verification and replay protection reuse the existing verified mechanism; correlation IDs
are server-generated for audit (PR #19 already enforces this); provider credentials and
raw payloads never enter logs, traces, notifications or responses — attempts store a
digest only; retention registers settlement records as `FinancialRecord`
(`DeletionMode::Archive`, `honoursErasureRequest() === false`, already modelled).

---

## 14. Notification events

**Reuse `NotificationService`. Replace `LoggingPaymentNotifier` (F10).** Category
`Payment`, class `Transactional`:

`settlement.created` (merchant) · `settlement.succeeded` (merchant) ·
`settlement.failed` (merchant + ops) · `payout.initiated` (merchant) ·
`payout.succeeded` (merchant) · `payout.failed` (merchant + ops) ·
`reconciliation.required` (**ops only**)

Amounts and merchant-visible references only. Never a provider reference to a customer,
never credentials, never raw payloads. **Notification failure must never roll back a
settlement** — the M26 defect (service resolved outside the try/catch) is the specific
trap to avoid; PR #19's notification lifecycle already provides `PermanentlyFailed`.

---

## 15. Feature flags

All safe defaults **OFF**, registered in the existing `FlagRegistry` with owner, rollout
and rollback (the registry refuses a flag lacking them):

| Flag | Purpose | Rollback |
|---|---|---|
| `settlement.accrual` | write payable accruals on order settlement | disable; accruals stop. Backfillable from ledger — no money at risk |
| `settlement.compute` | build draft runs | disable; drafts stop. Drafts move no money |
| `settlement.execute` | **actually pay merchants** | disable; in-flight attempts finish, no new ones. The kill switch |
| `settlement.auto_approve` | skip manual approval below a threshold | disable; every run needs a human |
| `settlement.reconcile` | scheduled reconcilers | disable; sweeps stop, cases persist |
| `payments.orchestrator` *(declared in PR #19)* | reserved | — |
| `settlement.new_flow` *(declared in PR #19)* | route to `SettlementRun` instead of legacy | disable; legacy path |

**Activation order:** accrual (report-only, one full cycle) → compute (drafts reviewed
against manual figures) → reconcile (read-only, discrepancies triaged) → execute (one
merchant, then a cohort, then all). Scheduler entries registered but **disabled**;
`DISPATCH_ENGINE_ENABLED` untouched.

---

## 16. API / OpenAPI changes

**Merchant-facing** (scoped to the authenticated merchant): `GET /merchant/payable`,
`/settlements`, `/settlements/{id}`.

**Admin**: `GET /admin/settlements/queue`, `/payables`, `/settlement-runs/{id}`,
`/payout-attempts`, `/reconciliation-cases`, `/settlement-health`;
`POST /admin/settlement-runs` (compute draft, `finance.settle`),
`/{id}/approve` (`finance.settle`), `/{id}/execute` (`finance.payout`, **idempotency key
required**), `/{id}/retry` (`finance.payout`),
`/admin/reconciliation-cases/{id}/investigate` (`finance.reconcile`),
`/resolve` (`finance.adjust`), `/admin/settlement-runs/{id}/reverse` (`finance.reverse`).

OpenAPI 3.0.3 — no `type: "null"` (the M26 CI lesson: use `allOf` + `nullable` + explicit
`type`). No spec changes in this planning phase.

---

## 17. Testing and negative-control strategy

Unit (state machines, `SettlementReference`, payable derivation, currency mismatch) ·
integration (accrual → compute → approve → execute → confirm; refund reducing payable) ·
authorization (**every money-moving route rejects `finance.read`-only** — the F5
regression test) · IDOR (merchant A cannot read B's payable/settlements) · DB constraint
(insert a duplicate `settlement_lines.accrual_id` directly and assert the DB refuses) ·
provider adapters (success, decline, timeout → `Unknown`) · webhook (duplicate, forged
signature, replay) · reconciliation (each of the four, each discrepancy kind, plus
"discrepancy is never auto-corrected") · failure recovery (crash between submit and
record → `UNKNOWN` → reconcile) · **PostgreSQL concurrency 33/33** · full M23/M24/M25/M26
+ cross-cutting regression.

**Negative controls — mandatory.** For each of at least: the `accrual_id` unique index,
the live-run partial index, the payable row lock, the in-lock re-check, optimistic
versions, the `UNKNOWN`-cannot-retry rule, the `RESOLVED_ADJUSTED` approver+posting
`CHECK`, `finance.settle`/`payout`/`adjust` guards, currency match, ledger balance,
payable non-negativity, and webhook dedup — **remove the protection, prove the test
fails, restore.** Report every false positive found rather than quietly fixing the test;
this session found three such tests, and the technique is why.

---

## 18. Migration / backfill strategy

Additive only; legacy `payments_settlements`/`payments_payouts` retained read-only.

**Accrual backfill** is the one risky migration: derive historical accruals from confirmed
payments and settled orders. It must be **reversible, counted, dry-runnable, and
idempotent on `order_id`**, and it must **not** post ledger entries for periods already
settled through the legacy path — double-counting historical payables is the worst
available outcome. Recommendation: backfill accruals **in a reporting-only state** first,
reconcile against legacy settlements, and only then activate. Follows the M26 vehicle
backfill and the M27 timezone-classification precedents: classify, exclude by default,
count, log, reverse.

---

## 19. Risk register

| # | Risk | Sev | Mitigation |
|---|---|---|---|
| R1 | Duplicate merchant payment | **Critical** | unique `settlement_lines.accrual_id`; idempotency; 4-layer concurrency; scenarios 1, 2, 6, 7 |
| R2 | Money leaves, no record (F3) | **Critical** | `UNKNOWN` state; attempt row written *before* the call; reconciliation; scenario 9 |
| R3 | Read permission moves money (F5) | **Critical** | new permissions in step 1; authorization regression test |
| R4 | Accrual backfill double-counts | **Critical** | reporting-only first, reconcile, dry-run, reversible |
| R5 | Payable derived from a stale snapshot | High | snapshots never authoritative; reconciler compares |
| R6 | Provider `Unknown` retried as failure | High | no `UNKNOWN → PENDING` transition; negative control |
| R7 | Commission drift after rate change | High | rate snapshot on the accrual; posted at capture |
| R8 | Cross-currency settlement | High | `CHECK` constraints + explicit rejection; no FX in M27 |
| R9 | Notification failure rolls back settlement | Medium | listener registration inside try/catch (M26 lesson) |
| R10 | Escrow ≠ Σ payables after go-live | Medium | ledger↔payable reconciler from day one |
| R11 | Legacy and new settlement paths both active | Medium | `settlement.new_flow` flag; legacy frozen read-only |
| R12 | Scheduler accidentally enables execution | **Critical** | tasks registered **disabled**; test asserting 0 enabled money-moving tasks |

---

## 20. Implementation sequence

1. **Security fix first** — new finance permissions; move `POST admin/settlements` off
   `finance.read`. Independently shippable; fixes F5 before anything new is built.
2. `MerchantPayable` ledger account + `GatewayResult` outcome enum + `fetchTransferStatus`
3. `PayableAccrual` + accrual on `OrderSettled` (flag `settlement.accrual`, report-only)
4. Payable derivation + merchant-facing read APIs
5. `SettlementRun` + `SettlementLine` + DB constraints (compute drafts only)
6. Approval workflow + audit
7. `PayoutAttempt` + execution + `UNKNOWN` handling (flag `settlement.execute`, off)
8. `ReconciliationCase` + four reconcilers (read-only)
9. Admin/GCC backend APIs
10. Notifications via `NotificationService`
11. Scheduler entries — **registered disabled**
12. Extend M23 harness to 33 scenarios
13. Full regression + negative-control audit
14. Backfill strategy, dry-run, documentation

---

## 21. Explicit M28+ exclusions

**Not in M27:** disputes and chargeback workflows (a settlement may be *held* pending a
dispute; adjudication is M28) · proof of delivery — the audit found no settlement
dependency on it, since accrual keys off order-settled ∧ payment-confirmed · FX and
cross-currency settlement (rejected, not converted) · rider/driver payouts beyond the
existing wallet path · dynamic commission or revenue engine (M33) · GCC UI (M32) · mobile
merchant UI (M34) · full Trust Engine (M29) — the risk seam is reused, not extended ·
tax/VAT computation · any dispatch, rider-matching or fleet-map change · rewrites of
existing payment provider adapters.

---

## 22. Definition of Done

Financial invariants 1–18 enforced and tested · PostgreSQL concurrency **33/33, 0 failed**
· PHPStan L8 = 0, Pint clean, Redocly valid, OpenAPI generation passing · SQLite +
PostgreSQL suites green with M23/M24/M25/M26 + cross-cutting regressions inside them ·
negative-control audit: **every** safety-critical protection proven to fail its test when
removed, with every false positive reported · migrate → rollback → re-migrate clean ·
current-schema DR restore passing · no money-moving route reachable with `finance.read`
alone · all settlement flags **off**, scheduler money-moving tasks **disabled**, dispatch
untouched · backfill reversible, counted, dry-runnable · no secrets, credentials, raw
provider payloads or PII in logs, traces, notifications or responses · M28 not started.
