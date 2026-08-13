# Milestone 23 — Financial Integrity Remediation · Implementation Plan

**Status:** approved, in progress · **Branch:** `claude/m23-financial-integrity`
**Scope rule:** remediation only. No M24+ features (no KYC, Maps, Dispatch, Payment
Orchestrator, split settlement, POD, AI, catalogue expansion). No Marketplace /
Commerce consolidation — that remains scheduled between M27 and M28.

---

## 1. Defect inventory (audit result)

The two wallet defects named in the assessment are instances of two systemic
classes. The audit found **seven** read-modify-write races and **seven** split
transaction boundaries across four modules. Per the approved direction (item 7),
all of them are remediated in M23 rather than carried forward.

### Class A — unguarded read-modify-write (double-spend)

| # | Operation | Location | Consequence |
|---|---|---|---|
| A1 | Wallet balance | `EloquentWalletRepository::findForOwner/findById` → `save` | Overdraft; wrong `balance_after_minor` |
| A2 | Refundable amount | `RefundService::request` | **Double refund — real money loss** |
| A3 | Loyalty points balance | `EloquentLoyaltyAccountRepository::find*` → `save` | Points double-spend |
| A4 | Reward stock | `RedemptionService::redeem` → `Reward::consumeStock` | Oversold rewards |
| A5 | Coupon redemption count | `Commerce CheckoutService::place` → `Coupon::redeem` | Coupon over-redemption |
| A6 | Grocery inventory | `Commerce CheckoutService::place` → `InventoryItem::deduct` | Oversold stock |
| A7 | Restaurant menu stock | `Marketplace CheckoutService::checkout` → `MenuItem::reduceStock` | Oversold stock |

### Class B — split transaction boundaries (atomicity)

| # | Operation | Location | Consequence |
|---|---|---|---|
| B1 | Wallet transfer | `WalletService::transfer` — two `save()` calls, two transactions | Money destroyed or duplicated |
| B2 | Wallet order payment | `WalletService::payFromWallet` — debit and escrow credit separate | Customer debited with no escrow |
| B3 | Restaurant checkout | `Marketplace CheckoutService::checkout` — N repository calls | Stock decremented against no order |
| B4 | Grocery checkout | `Commerce CheckoutService::place` — N repository calls | Same, plus coupon consumed with no order |
| B5 | Refund | `RefundService::request` — 4 persistence ops around an HTTP call | Refunded payment with no refund row, or no ledger entry |
| B6 | Settlement | `SettlementService::settle` — 5+ ops around an HTTP transfer | Money transferred, settlement left `processing` |
| B7 | Redemption | `RedemptionService::redeem` — 3 saves | Points debited with no redemption issued |

### Class C — idempotency gaps

No idempotency key is accepted by checkout (both stacks), refunds, settlements,
payouts, wallet top-up/transfer, or redemption. Webhook dedup has a
check-then-record race that permits double-capture under concurrent delivery.

### Class D — structural / RBAC

| # | Finding |
|---|---|
| D1 | `payments_wallets.balance_minor` is `unsignedBigInteger`, which PostgreSQL renders as a plain signed `bigint` — **negative balances are possible at the database level on the production engine** |
| D2 | `payments_ledger_entries` is append-only by convention only — no DB enforcement |
| D3 | No reconciliation proving ledger debits equal credits |
| D4 | `ADMIN_IDENTITY_ADMIN_IS_SUPER` defaults `true` — any Identity `admin` JWT becomes SuperAdmin |
| D5 | Payments admin money routes gate on coarse `role:admin`, not `finance.*` permissions |

---

## 2. Strategy

Rather than seven bespoke patches, M23 introduces three reusable Shared-kernel
primitives and applies them consistently. This keeps the modular monolith intact —
Application layers depend on **domain ports**, never on `Illuminate\Support\Facades\DB`.

**P1 · `TransactionManager` port** (`Shared\Domain\TransactionManager`)
`atomic(callable): mixed` with deadlock retry. Laravel adapter in
`Shared\Infrastructure\Transaction`. Lets Application services define one
transaction boundary per use case without a framework import.

**P2 · Locking reads on repository ports**
Explicit `…ForUpdate()` methods added to the domain repository interfaces whose
aggregates carry a balance or a stock counter. Implemented with `lockForUpdate()`.
On SQLite Laravel compiles this to a no-op, which is correct — SQLite serialises
writers — so the shared test path is unaffected.

**P3 · `IdempotencyStore` port** (`Shared\Domain\Idempotency`)
Claim-execute-complete against a uniquely-indexed key table. The unique index is
the mutex, so a concurrent duplicate is rejected by the database rather than by a
check.

**External I/O rule.** No provider HTTP call happens inside a database
transaction. Every operation that talks to a gateway is restructured as
**reserve (atomic) → call provider → settle outcome (atomic)**. The reservation
is what prevents the double-spend; the split keeps locks short and avoids
uncommittable state.

---

## 3. Files and modules affected

### New — Shared kernel
```
modules/Shared/src/Domain/TransactionManager.php
modules/Shared/src/Domain/Exception/ConcurrencyConflict.php
modules/Shared/src/Domain/Idempotency/IdempotencyStore.php
modules/Shared/src/Domain/Idempotency/IdempotentResult.php
modules/Shared/src/Domain/Exception/IdempotencyConflict.php
modules/Shared/src/Infrastructure/Transaction/LaravelTransactionManager.php
modules/Shared/src/Infrastructure/Idempotency/EloquentIdempotencyStore.php
modules/Shared/src/Infrastructure/Idempotency/Model/IdempotencyKeyModel.php
modules/Shared/src/Infrastructure/Persistence/Migration/2027_02_01_000001_create_shared_idempotency_keys_table.php
```

### Modified — Payments
```
src/Domain/Wallet/WalletRepository.php            + findForOwnerForUpdate, findByIdForUpdate
src/Domain/Payment/PaymentRepository.php          + findByIdForUpdate
src/Domain/Wallet/Wallet.php                      + version, reserve/apply semantics
src/Domain/Ledger/LedgerIntegrity.php             (new) reconciliation port
src/Application/Service/WalletService.php         atomic transfer / payFromWallet / credit / debit
src/Application/Service/RefundService.php         reserve → provider → settle; idempotent
src/Application/Service/SettlementService.php     reserve → provider → settle; idempotent
src/Application/Service/PaymentService.php        capture + persist in one transaction
src/Application/Service/WebhookService.php        claim-first exactly-once
src/Application/Service/LedgerIntegrityService.php (new)
src/Domain/Webhook/WebhookEventRepository.php     + claim()
src/Infrastructure/Persistence/Eloquent/EloquentWalletRepository.php
src/Infrastructure/Persistence/Eloquent/EloquentPaymentRepository.php
src/Infrastructure/Persistence/Eloquent/EloquentWebhookEventRepository.php
src/Infrastructure/Persistence/Eloquent/EloquentLedgerRepository.php
src/Infrastructure/Provider/PaymentsServiceProvider.php
src/Interface/Http/Controller/WalletController.php     accept Idempotency-Key
src/Interface/Http/Controller/RefundController.php     accept Idempotency-Key
src/Interface/Http/routes.php                          finance permission gate
src/Infrastructure/Console/VerifyLedgerCommand.php     (new)
```

### Modified — Marketplace / Commerce / Loyalty / Admin / Identity
```
Marketplace  CheckoutService, MenuItemRepository(+ForUpdate), EloquentMenuItemRepository, CheckoutController
Commerce     CheckoutService, InventoryRepository(+ForUpdate), CouponRepository(+ForUpdate),
             EloquentInventoryRepository, EloquentCouponRepository, CheckoutController
Loyalty      RedemptionService, LoyaltyService, LoyaltyAccountRepository(+ForUpdate),
             RewardRepository(+ForUpdate), Eloquent* repositories
Admin        EnsurePermission middleware (new), AdminServiceProvider (alias registration)
config       admin.php (identity_admin_is_super → false), .env.example
```

---

## 4. Database changes

All additive or constraint-only; expand/contract respected; nothing dropped or renamed.

| Migration | Change | Engine notes |
|---|---|---|
| `2027_02_01_000001_create_shared_idempotency_keys_table` | New table: `key` + `scope` unique, `request_hash`, `state`, `response_snapshot`, `expires_at` | Portable |
| `2027_02_01_000002_add_version_to_payments_wallets` | `version` unsigned integer default 0 | Portable |
| `2027_02_01_000003_add_wallet_balance_guards` | `CHECK (balance_minor >= 0)` on `payments_wallets` | PostgreSQL + SQLite; guarded by driver |
| `2027_02_01_000004_add_loyalty_balance_guard` | `CHECK (balance >= 0)` on `loyalty_accounts` | Same |
| `2027_02_01_000005_protect_ledger_append_only` | `BEFORE UPDATE OR DELETE` trigger on `payments_ledger_entries` raising an exception | PostgreSQL only, **explicit driver check — not a silent try/catch** |
| `2027_02_01_000006_add_refund_idempotency` | Unique `(payment_id, idempotency_key)` on `payments_refunds`; nullable `idempotency_key` | Portable |

`migrate:fresh → rollback → migrate` on an empty PostgreSQL must stay green — this
is a mandatory `release.yml` gate. Every migration ships a working `down()`.

---

## 5. Transaction-boundary changes

| Use case | Before | After |
|---|---|---|
| `WalletService::transfer` | 2 transactions | 1 transaction, both wallets locked in deterministic id order (deadlock avoidance) |
| `WalletService::payFromWallet` | 2 transactions | 1 transaction, customer + platform wallet locked in id order |
| `Marketplace::checkout` | N transactions | 1 transaction: lock items → decrement → order → delivery → clear cart |
| `Commerce::place` | N transactions | 1 transaction: lock inventory + coupon → deduct → order → clear cart |
| `RefundService::request` | 4 ops around HTTP | txn1 lock payment + reserve amount + create pending refund → HTTP → txn2 complete + ledger (or release reservation) |
| `SettlementService::settle` | 5+ ops around HTTP | txn1 open + mark processing → HTTP transfer → txn2 complete + wallet credit + ledger |
| `RedemptionService::redeem` | 3 saves | 1 transaction: lock account + reward → debit + consume stock → issue |
| `PaymentService::open` | ledger and payment separate | HTTP outside; capture + persist + ledger in 1 transaction |
| `WebhookService::handle` | check → apply → record | claim (unique insert) → apply, all in 1 transaction |

---

## 6. Concurrency strategy

1. **Pessimistic locking is the primary control.** Every balance or stock mutation
   reads its row with `SELECT … FOR UPDATE` inside the use-case transaction.
2. **Deterministic lock ordering.** Multi-row operations (transfer, checkout) sort
   the target ids before locking, so two opposing operations cannot deadlock.
3. **Database CHECK constraints are the structural backstop.** Even if a future
   code path forgets the lock, `balance_minor >= 0` makes overdraft impossible.
   This also closes D1, where PostgreSQL's signed `bigint` silently permitted
   negative balances.
4. **Optimistic `version` on wallets** as lost-update detection, raising
   `ConcurrencyConflict` (HTTP 409) rather than silently overwriting.
5. **Deadlock retry** in `LaravelTransactionManager` (3 attempts), so a legitimate
   serialisation conflict retries instead of surfacing to the caller.
6. **Uniqueness as a mutex** for idempotency and webhook claims — the database,
   not application logic, arbitrates the race.

---

## 7. RBAC changes

- `config/admin.php`: `identity_admin_is_super` default flips `true` → **`false`**.
  A coarse Identity `admin` role no longer confers SuperAdmin. Back-office access
  now requires either a real `admin_accounts` grant or an explicit
  `ADMIN_SUPER_ADMINS` bootstrap id.
- `.env.example` documents `ADMIN_IDENTITY_ADMIN_IS_SUPER=false` and
  `ADMIN_SUPER_ADMINS` so a fresh deployment can bootstrap without the shortcut.
- New `EnsurePermission` middleware (alias `permission:`) in the Admin module,
  resolving through `PermissionService` so the nine-role model is genuinely enforced
  at the route layer.
- Payments admin money routes move from `role:admin` to `permission:finance.read`.
  **Scope limit:** other modules' `role:admin` routes are deliberately untouched —
  unified RBAC across all contexts is M32, and changing them here would be an
  unrequested behaviour change.

---

## 8. Tests to add

### Pest — deterministic, run on both engines
```
modules/Shared/tests/Unit/TransactionManagerTest.php
modules/Shared/tests/Feature/IdempotencyStoreTest.php
modules/Payments/tests/Feature/WalletAtomicityTest.php        transfer rollback, insufficient funds, 409 on conflict
modules/Payments/tests/Feature/RefundIdempotencyTest.php      duplicate key replay, double-refund rejection
modules/Payments/tests/Feature/WebhookExactlyOnceTest.php     duplicate delivery applies once
modules/Payments/tests/Unit/LedgerIntegrityTest.php           debits == credits per correlation
modules/Marketplace/tests/Feature/CheckoutAtomicityTest.php   forced failure leaves no stock drift
modules/Commerce/tests/Feature/CheckoutAtomicityTest.php      coupon + inventory rollback
modules/Loyalty/tests/Feature/RedemptionAtomicityTest.php     points + stock rollback
modules/Admin/tests/Feature/SuperAdminEscalationTest.php      Identity admin ≠ SuperAdmin
modules/Payments/tests/Feature/FinancePermissionTest.php      finance routes need finance.read
```

### Crash / failure simulation
Injected-failure doubles (a repository or gateway that throws mid-use-case) assert
that the whole unit rolls back and no partial state survives — the closest
deterministic analogue to a process crash inside a transaction.

### True concurrency — PostgreSQL only
```
scripts/financial_concurrency_validation.php   orchestrator
scripts/financial_concurrency_worker.php       forked worker
```
Mirrors the existing `redis_validation.php` / `redis_concurrency_worker.php`
pattern. Spawns real OS processes against a real PostgreSQL database with **no
wrapping transaction** (Pest's `RefreshDatabase` makes in-suite concurrency
testing impossible), exercising:

- N concurrent debits on one wallet → balance never negative, exactly one succeeds per available unit
- N concurrent transfers → conserved total across both wallets
- N concurrent refunds on one payment → refunded total never exceeds the payment
- N concurrent checkouts on 1 unit of stock → exactly one order
- N concurrent redemptions on 1 reward → exactly one redemption
- N concurrent identical webhook deliveries → exactly one capture, one ledger group
- N concurrent requests with one idempotency key → one execution, N identical responses

---

## 9. Security validation

- Escalation test proving an Identity `admin` JWT is refused by `/v1/admin/*`.
- Permission test proving finance routes require `finance.read`.
- Ledger tamper test proving `UPDATE`/`DELETE` on `payments_ledger_entries` is
  rejected by PostgreSQL.
- Negative-balance test proving the CHECK constraint rejects a direct SQL overdraft.
- Idempotency replay test proving a replayed key cannot move money twice.
- Full existing gates re-run: Pint, PHPStan Level 8 (0 errors required), the whole
  Pest suite on SQLite **and** PostgreSQL 16, and `migrate:fresh → rollback → migrate`.

---

## 10. Rollback considerations

- **Every migration is additive or a constraint**; no column or table is dropped or
  renamed, so the release is safe to deploy ahead of the code (expand phase) and
  the previous application version continues to run against the new schema.
- **Reversible `down()`** on all six migrations, including dropping the trigger and
  the CHECK constraints.
- **The CHECK constraints will reject pre-existing bad rows.** Each constraint
  migration first asserts no violating row exists and fails loudly with a clear
  message if one does, rather than aborting mid-`ALTER`.
- **The RBAC flip is the one behavioural change that can lock out an operator.**
  Mitigation: `ADMIN_SUPER_ADMINS` accepts bootstrap ids, `AdminSeeder` provisions a
  super admin, and the setting remains env-overridable — a deployment can set
  `ADMIN_IDENTITY_ADMIN_IS_SUPER=true` for one release while real admin accounts are
  granted, then flip it off.
- **Rollback path:** revert the application deploy; the schema additions are inert
  to the previous version. The ledger trigger is the only object that would reject
  writes the old code might attempt — but the old code never updates ledger rows, so
  it is safe to leave in place.
