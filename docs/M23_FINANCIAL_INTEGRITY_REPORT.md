# Milestone 23 — Financial Integrity Remediation · Certification Report

Branch: `claude/m23-financial-integrity` · Base: `c9e145a` (post-M22)
Verdicts use the project's four labels: **EXECUTED — PASSED / EXECUTED — FAILED /
STATIC VALIDATION ONLY / NOT VALIDATED**. Nothing here is claimed without a run.

Plan of record: [`M23_IMPLEMENTATION_PLAN.md`](M23_IMPLEMENTATION_PLAN.md).

---

## 1. What M23 fixed

The approved assessment named two wallet defects. The audit found they were
instances of two systemic classes, and **all instances are remediated** rather
than only the two named ones (approved direction, item 7).

### Class A — unguarded read-modify-write (double-spend)

| Operation | Remedy |
|---|---|
| Wallet balance | `SELECT … FOR UPDATE` + `version` column + `CHECK (balance_minor >= 0)` |
| Refundable amount | Payment row locked; amount reserved as a `pending` refund |
| Loyalty points balance | `findByUserForUpdate()` inside the redemption transaction |
| Reward stock | `findByIdForUpdate()` before `consumeStock()` |
| Coupon redemption count | `findByCodeForUpdate()` before `redeem()` |
| Grocery inventory | `findForProductForUpdate()`, deducted in stable product order |
| Restaurant menu stock | `findByIdForUpdate()`, locked in stable id order |

### Class B — split transaction boundaries (atomicity)

Every one of these now runs inside a single `TransactionManager::atomic()`
boundary: wallet transfer, wallet order payment, restaurant checkout, grocery
checkout, refund settle, settlement completion, loyalty redemption, payment
capture, webhook application.

**External I/O is never inside a transaction.** Operations that call a provider
are restructured as **reserve (atomic) → call provider → settle outcome
(atomic)**. The reservation prevents the double-spend; the split keeps locks
short and avoids uncommittable state.

### Class C — idempotency

`Idempotency-Key` is now honoured on restaurant checkout, grocery checkout,
refunds, wallet top-up and wallet transfer. Webhook processing claims the event
by unique-index insert *before* doing the work, inside the same transaction.

### Class D — structural and RBAC

| Finding | Remedy |
|---|---|
| `balance_minor` was a signed `bigint` on PostgreSQL despite the `unsigned` declaration — negative balances were possible at the database level | `CHECK (balance_minor >= 0)`, verified by direct SQL |
| Ledger append-only by convention only | `BEFORE UPDATE OR DELETE` trigger raising an exception |
| No proof the ledger balances | `LedgerIntegrityService` + `php artisan payments:verify-ledger` |
| `ADMIN_IDENTITY_ADMIN_IS_SUPER` defaulted **true** — any Identity `admin` JWT became SuperAdmin | Default flipped to **false**; `permission:` middleware added; Payments money routes moved from `role:admin` to `permission:finance.read` |

---

## 2. Test results

| Suite / gate | Engine | Verdict |
|---|---|---|
| Pest — full suite | SQLite `:memory:` | **EXECUTED — PASSED** — 381 passed, 1 skipped, 1544 assertions |
| Pest — full suite | **PostgreSQL 16.13** | **EXECUTED — PASSED** — 382 passed, 1548 assertions |
| PHPStan Level 8 + Larastan | — | **EXECUTED — PASSED** — 0 errors (no baseline, no suppression) |
| Pint (PSR-12) | — | **EXECUTED — PASSED** |
| `migrate:fresh` → `rollback` → `migrate` | PostgreSQL 16 | **EXECUTED — PASSED** — 105 tables |
| Redis primitives | Redis 7 | **EXECUTED — PASSED** — 9/9 |
| OpenAPI contract (redocly) | — | **EXECUTED — PASSED** — 0 errors; warnings 410 → 409 |
| **Financial concurrency** | **PostgreSQL 16, real OS processes** | **EXECUTED — PASSED** — 23/23 |

The one SQLite skip is the ledger append-only trigger, which is a PostgreSQL
object. It is skipped rather than faked so the SQLite run does not assert a
protection that is not present there; it executes on the production engine.

### Baseline preserved

The pre-M23 suite was **338 passed / 0 failed**. It is still 338 passing, plus
**44 new M23 tests**. No existing test was weakened, skipped or deleted.

---

## 3. Concurrency results (the part a single-process suite cannot prove)

`RefreshDatabase` wraps each Pest test in a transaction, so a second connection
never sees the first one's rows — in-suite concurrency testing is impossible by
construction. `scripts/financial_concurrency_validation.php` launches real OS
processes against a real PostgreSQL database, synchronised on a shared start
instant so their statements genuinely collide.

| Scenario | Result |
|---|---|
| 20 concurrent debits against a balance covering 10 | exactly 10 succeed; final balance 0; never negative |
| 16 concurrent transfers, half in each direction (deadlock shape) | total conserved at 20,000; **no deadlock reached the caller** |
| 12 concurrent refunds of 20,000 against a 100,000 payment | exactly 5 succeed; refunded total exactly 100,000, never more |
| 8 concurrent checkouts on 1 unit of stock | exactly 1 order; stock not oversold |
| 8 concurrent redemptions of a 1-stock reward | exactly 1 voucher; stock not oversold |
| 12 concurrent duplicate webhook deliveries | applied exactly once; 1 event row |
| 12 concurrent requests sharing one idempotency key | work executed exactly once |
| Ledger after all of the above | balances; net 0 |

### Negative control — EXECUTED

A test that passes without the fix proves nothing. The row lock and version
guard were temporarily removed and the suite re-run against the same database:

```
✘ exactly 10 of 20 concurrent debits succeed  (succeeded=20 rejected=0)
✘ final balance is exactly zero, never negative  (balance=6000)
   worker error: Illuminate\Database\DeadlockException: SQLSTATE[40P01]: Deadlock detected
RESULT: 19 passed, 4 failed
```

All 20 debits succeeded against a balance that covered 10, leaving **6,000 minor
units of value created from nothing**, plus genuine PostgreSQL deadlocks
surfacing to callers. The fix was restored and the suite returned to 23/23. The
scenarios detect the real defect.

---

## 4. Security validation

| Control | Verdict |
|---|---|
| Identity `admin` JWT refused by `/v1/admin/*` | **EXECUTED — PASSED** |
| Identity `admin` JWT refused by `/v1/payments/admin/*` | **EXECUTED — PASSED** |
| Genuinely granted SuperAdmin still admitted | **EXECUTED — PASSED** |
| `bootstrap_super_admins` escape hatch works | **EXECUTED — PASSED** |
| FinanceManager reaches finance, refused RBAC and config | **EXECUTED — PASSED** |
| ContentManager reaches CMS, refused finance | **EXECUTED — PASSED** |
| Unauthenticated finance call → 401 | **EXECUTED — PASSED** |
| Direct SQL negative wallet balance rejected | **EXECUTED — PASSED** (CHECK constraint) |
| `UPDATE` / `DELETE` on a ledger entry rejected | **EXECUTED — PASSED** (trigger, PostgreSQL) |
| Idempotency key replay cannot move money twice | **EXECUTED — PASSED** |
| Idempotency key reuse with a different body → 422 | **EXECUTED — PASSED** |

---

## 5. A defect found *by* the PostgreSQL run

Worth recording because it would not have appeared on SQLite. The first
PostgreSQL run failed 10 tests with:

```
SQLSTATE[25P02]: In failed sql transaction: current transaction is aborted
```

On PostgreSQL a constraint violation aborts the **entire enclosing
transaction**, not just the failing statement. Both the idempotency claim and
the webhook claim rely on catching a unique-violation and then reading the
existing row — which is impossible once the transaction is poisoned. Both now
wrap the insert so it becomes a `SAVEPOINT` when nested, and only the losing
insert rolls back.

This was a real production defect in newly-written code, caught only because the
suite was run on the production engine rather than SQLite alone.

---

## 6. Rollback considerations

- All six migrations are additive or constraint-only; nothing is dropped or
  renamed, so the schema is safe to deploy ahead of the code (expand phase) and
  the previous application version runs unchanged against it.
- Each migration has a working `down()`, verified by the rollback gate.
- The CHECK constraint migration **asserts no violating row exists** and fails
  with a clear message rather than aborting mid-`ALTER`.
- The RBAC flip is the one behavioural change that can lock out an operator.
  Mitigations: `ADMIN_SUPER_ADMINS` accepts bootstrap ids, and
  `ADMIN_IDENTITY_ADMIN_IS_SUPER=true` can be set for one release while real
  admin accounts are provisioned. **This must be planned before deploying.**
- Rollback path: revert the application deploy; the schema additions are inert
  to the previous version. The ledger trigger only blocks `UPDATE`/`DELETE`,
  which the old code never performed, so it is safe to leave in place.

---

## 7. Remaining risks (not addressed in M23 — deliberately)

1. **Settlement still takes a caller-supplied gross.** `SettlementService::settle()`
   is now atomic and its provider call is outside the transaction, but nothing
   derives settleable gross from captured, delivered orders. That is M27.
2. **Orders are still not linked to payments.** No `payment_id` on either order
   table, so order-to-payment reconciliation remains impossible. M27.
3. **Splits are still not transmitted to providers.** Unchanged by M23. M27.
4. **`AllowAllFraudDetector` is still the only fraud implementation.** M27.
5. **Other modules' admin routes still gate on `role:admin`.** Only the Payments
   money routes moved to permissions; unified RBAC across all contexts is M32.
   Deliberately not widened here to avoid an unrequested behaviour change.
6. **A crashed request leaves its idempotency key `in_progress` until expiry**
   (24h default), blocking retries under that key. This is fail-closed by
   design; a shorter TTL or a janitor job can reduce the window.
7. **`purgeExpired()` has no scheduled caller.** The table grows until one is
   added; rows are small and bounded by traffic × TTL.

---

## 8. Verdict

Every defect in classes A–D is remediated and **executed-green on the production
database engine**, including true multi-process concurrency with a negative
control proving the scenarios detect the original defect. The pre-existing suite
is unchanged and still passing, PHPStan Level 8 remains at 0, and the migration,
Redis and contract gates are unaffected.

> **M23 is complete and independently validated.**
> The financial-integrity precondition on M27 (Payment Orchestrator, splits,
> settlement, POD) is satisfied.
