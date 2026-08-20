# M27 — Financial Settlement, Merchant Payout & Reconciliation

**Implementation report.** Baseline `fc412dcd9721fd9d3fb48f4b81f804819bdf014f`.

---

## 1. What M27 changed, in one paragraph

Settlement already existed. It took the amount to pay a merchant from a request
body, was reachable with a read-only permission, called the provider outside any
transaction, and could not tell a declined transfer from a timed-out one. M27
derives the amount from accrual records, splits the permission six ways,
separates approving from executing by both permission *and* person, writes the
payout attempt down before the provider is called, and introduces an `unknown`
state with no path back to a payment. Everything is behind flags that ship off.

---

## 2. The four defects that shaped the design

### F5 — a read permission authorised a bank transfer

`POST /api/v1/payments/admin/settlements` transfers money to a bank account and
was guarded by `permission:finance.read`. Fixed first, and independently
shippable.

`finance.read` now sees money and moves none. Five permissions were added:

| Permission | Authorises | Roles |
|---|---|---|
| `finance.read` | viewing ledgers, payables, runs, attempts | SuperAdmin, Admin, FinanceManager |
| `finance.settle` | computing and approving a run | SuperAdmin, FinanceManager |
| `finance.payout` | performing the transfer | SuperAdmin, FinanceManager |
| `finance.reconcile` | investigating a discrepancy | SuperAdmin, FinanceManager |
| `finance.adjust` | closing a case by changing the books | **SuperAdmin only** |
| `finance.reverse` | clawing back a completed payout | **SuperAdmin only** |

A correction to the plan's audit: it stated CustomerSupport held `finance.read`.
It does not, and never did — the roles holding it were SuperAdmin, Admin and
FinanceManager. The finding stands (a *read* permission authorised a transfer);
the CustomerSupport detail was wrong. The regression suite asserts CustomerSupport
cannot settle anyway, because the risk is a future grant.

### F1 — the payable was typed in, not derived

`SettlementService::settle()` took `int $grossMinor` straight from the request.

M27's amounts come from **the ledger's capture posting for the payment** — the
entries written when the money arrived, protected by an append-only trigger, and
the same rows an auditor would use. Not from the caller, and not from the
commission calculator either: recomputing commission at settlement time would
apply today's rate to a sale from last month.

`SettledOrder`, the contract Marketplace calls, carries three identifiers and no
amounts. There is no field on any M27 endpoint that can change what a merchant
is paid.

### F3 / F4 — a timeout read as a decline

`GatewayResult` carried `bool $success`. Two values, three realities: succeeded,
declined, and *we do not know*. The third collapsed into the second, and a
declined transfer is safe to retry — so a socket timeout on a bank transfer
could pay a merchant twice.

`GatewayOutcome` adds an explicit `Unknown`. `SettlementRunState::Unknown` has
exactly one outgoing transition, to `Reconciling`. The legacy boolean is
retained and derived from the enum so the seven shipped adapters keep working;
`GatewayOutcome::fromLegacy()` resolves an *unrecognised* failure to `Unknown`
rather than `Failed`, which is the safe side of that mistake.

The payout attempt row is now committed **before** the provider is called, so a
crash mid-transfer leaves a `created` row the reconciler looks for. Previously
it left nothing at all.

---

## 3. Defects found during implementation

Six were found by running the gates rather than by reading the code, and each
would have reached production.

| # | Defect | Found by | Consequence if shipped |
|---|---|---|---|
| 0 | Every HTTP provider adapter reported a transport failure on `transfer()` as `failed`, and let a connection exception escape the method entirely | **CI**, via a concurrency run that reached a live gateway | **The F3/F4 defect, still live in the five real adapters.** A 502 or a timeout on a bank transfer read as a *decline*, which is retryable — so the same money could go out twice. An escaping exception was worse: the payout attempt stayed `created` with the run stuck in `processing`, money possibly gone and nothing recorded to reconcile against. |
| 1 | `pg_advisory_xact_lock` was given a `crc32()` value up to 2³²−1, but the parameter is signed `int4` | PostgreSQL concurrency harness | Roughly **half of all merchant ids** would crash the settlement lock outright. Invisible to unit tests: it depends which uuid you hash. |
| 2 | The payable reconciler compared the ledger against lines from *reserved* runs, but the ledger only debits on success | Concurrency harness, scenario 19 | A false `PayableDrift` case for **every settlement in flight** — an alarm generator firing hardest when settlement is busiest. Renamed to `paidOutNetMinor()`, counting succeeded runs only. |
| 3 | The reversal posting used `"<run-id>:reversal"` as a correlation id; the column is `uuid` | PostgreSQL test suite | **Every reversal would fail** on the production engine and pass on SQLite. |
| 4 | `openOrReturnExisting` recovered from a unique violation by querying — but PostgreSQL aborts the whole transaction on a failed statement | PostgreSQL test suite | The reconciler would **crash the caller every time it met a discrepancy it had already recorded**, i.e. every sweep after the first. Fixed with a savepoint. |

A fifth was found while writing the migration: a partial unique index over a
**nullable** `subject_id` does not deduplicate, because `NULL != NULL`. Every
platform-level drift would have opened an unbounded number of cases. The column
is now `NOT NULL` with stable literal subjects (`merchant_payable`, `whole_book`).

Numbers 3 and 4 are the argument for running both engines: SQLite is permissive
about types and about failed statements inside transactions, and both defects
passed the fast suite cleanly.

Number 0 was found last and matters most. It is the argument for running the
gate in CI rather than only on a developer machine — and for the harness stating
its preconditions. See §11.

---

## 4. Architecture

### Where it lives

Inside **Payments**, as a `Settlement` sub-domain. The ledger, wallets, gateways
and escrow are already there; a separate context would have duplicated them or
reached into them.

### The new ledger account

`LedgerAccount::MerchantPayable`. `Escrow` conflated "money we hold" with "money
we owe a named merchant" — the first is a solvency question, the second is the
settlement question, and deriving the second from the first meant guessing.

```
capture:    Cash ↑ → Escrow ↑ (net), Commission ↑, Fees ↑
accrual:    Escrow → MerchantPayable          (per order, on delivery)
refund:     MerchantPayable → Escrow          (compensating row, never an edit)
settlement: MerchantPayable → Payouts         (on success only)
reversal:   Payouts → MerchantPayable         (compensating posting)
```

### Accruals, and why refunds are rows

`PayableAccrual` is one row per order (`earning`) plus one row per refund
(`refund_adjustment`, negative). A merchant's payable is the sum, less anything
committed to a live settlement run. Nothing is ever edited, so "was this merchant
underpaid, and when did we decide that?" stays answerable.

Commission is **not** clawed back on a refund — the merchant bears it. That is a
commercial decision, recorded in the aggregate rather than buried in a service,
and it errs toward under-paying rather than over-paying.

### The three acts

`computeDraft` derives and writes down. `approve` is a person saying the figure
is right. `execute` is a **different** person moving the money — enforced in the
aggregate, because only the run knows who approved it, and additionally by a
CHECK constraint for paths that never reach the aggregate.

### The execution sequence

```
┌─ transaction ────────────────────────────────┐
│ lock merchant → re-read run under lock →     │
│ check state → mark processing → write attempt│
└──────────────────────────────────────────────┘
                 ↓ commit
           provider transfer     ← no transaction, no locks held
                 ↓
┌─ transaction ────────────────────────────────┐
│ re-read under lock → record outcome → ledger │
└──────────────────────────────────────────────┘
```

The network call is outside any transaction because holding a row lock across a
twenty-second round trip blocks every other write on that merchant. That choice
is precisely why phase three must handle `Unknown` rather than assume.

### Reconciliation

Four reconcilers, all flag-gated, none of which corrects anything:

| Reconciler | Compares | Opens |
|---|---|---|
| provider ↔ platform | transfer status vs attempt | `payout_state_mismatch` |
| ledger ↔ payable | `MerchantPayable` balance vs derived | `payable_drift` |
| ledger integrity | signed sum of the book vs zero | `ledger_wallet_drift` |
| payments ↔ accruals | accruals with no captured payment | `orphan_accrual` |

Exactly one discrepancy kind is auto-resolvable: a provider mismatch, which can
genuinely resolve itself once the provider is reachable again. A drift between
two numbers the platform itself wrote cannot — asking twice gives the same
answer, and a system that "resolved" it would be hiding its own contradiction.

`ResolvedAdjusted` requires a named approver **and** the correlation id of a
compensating posting that already exists. The service verifies the posting is
real; the database enforces the pair with a CHECK.

---

## 5. The constraints that are the last line

| Constraint | Guarantees |
|---|---|
| `payments_settlement_lines(accrual_id)` **unique** | An accrual is paid for **once, ever**, platform-wide. The single most important constraint in M27. Plain, not partial — a cancelled run *deletes* its lines, so no state needs exempting. |
| `payable_accruals(order_id) WHERE type='earning'` unique | An order accrues once. Partial, because refund rows share the order id. |
| `payable_accruals(refund_id) WHERE NOT NULL` unique | A duplicated refund webhook reduces the payable once. |
| `settlement_runs(merchant,currency,window) WHERE state NOT IN (cancelled,failed,reversed)` unique | One live settlement per merchant and period, while still allowing a genuine retry. |
| `payout_attempts(provider,provider_reference) WHERE NOT NULL` unique | Two rows cannot claim the same transfer. |
| `reconciliation_cases(kind,subject_type,subject_id) WHERE unresolved` unique | One case per problem, not one per sweep. |
| CHECK `net = gross − commission − fee` | The arithmetic holds even for a backfill that bypasses the domain. |
| CHECK `state='resolved_adjusted' ⇒ resolver AND posting` | A case cannot be closed by writing a note. |
| CHECK `executed_by <> approved_by` | Separation of duties, in the schema. |
| Append-only triggers on accruals and settlement lines | History cannot be rewritten to justify a payout. |

Partial unique indexes work identically on SQLite and PostgreSQL, so the fast
test path proves the same rules production enforces. CHECK constraints and
triggers are PostgreSQL-only (SQLite cannot add them to an existing table) and
are proven by the concurrency harness.

---

## 6. Gate results

| Gate | Result |
|---|---|
| PostgreSQL 16 + Redis suite | **1250 passed, 0 failed** |
| SQLite suite | **1234 passed, 0 failed** (16 pgsql-only tests skip) |
| PHPStan level 8 | **0 errors** (1892 files) |
| Pint | clean |
| Redocly lint | **valid**, 0 errors (baseline: 0 errors — no regression) |
| OpenAPI TS generation | passes |
| migrate → rollback → re-migrate | clean, 145 migrations, all five M27 migrations reversible |
| M23 financial concurrency | 23/23 |
| **M27 concurrency (real OS processes, PostgreSQL)** | **57/57 passed, 0 failed** — under the CI environment, with `PAYMENTS_PROVIDER=mock`. See §11: the first CI run scored 51/57 because the harness reached a live provider. |
| M23/M24/M25/M26 regression | inside the suites above, green |
| Cross-cutting regression | inside the suites above, green |
| Security / authorization | 9 tests, including a structural route sweep |
| Webhook exactly-once | unchanged, green |
| Reconciliation | 18 tests |
| Failure / unknown-outcome | covered in lifecycle + harness scenarios 17–18 |
| **False-positive audit** | **18/18 protections confirmed, 0 false positives** |
| Report-only settlement cycle | 3 tests, exercised end to end |
| Feature flags OFF verification | 8 tests |

The concurrency target in the plan was 33 (23 existing + 10 new). The
implementation reached **57** because several scenarios needed more than one
assertion to be meaningful — the ten scenarios are all present.

---

## 7. Rollout

Flags, **in this order and no other**. Each stage makes the next one's mistakes
cheap.

1. `settlement.accrual` — record accruals. Report-only; nothing settleable, no
   ledger movement. Run for a full cycle and reconcile the totals by hand.
2. `settlement.accrual_posting` — post `Escrow → MerchantPayable`. This is what
   ends report-only mode.
3. `settlement.compute` — build draft runs. Review them against manual figures.
4. `settlement.reconcile` — the sweeps, read-only. Triage the first batch.
5. `settlement.execute` — **the financial kill switch.** One merchant, then a
   cohort, then all.

`settlement.auto_approve` is declared and is **not** part of this rollout;
enabling it removes the four-eyes rule for small runs and needs its own decision.

Scheduled work is registered **disabled** and reads no config that could flip it.
Nothing in M27 moves money on a timer — a person starts every payout.

`DISPATCH_ENGINE_ENABLED` is untouched, and a test asserts it.

---

## 8. Backfill strategy — NOT executed

Historical accruals for orders settled through the legacy path have **not** been
backfilled, and the migration to do it has **not** been written. This is
deliberate; it is the single riskiest operation in the milestone.

The requirements it must meet before anybody writes it:

- **Reporting-only first.** Derive accruals from confirmed payments and settled
  orders in the report-only state, reconcile them against the legacy
  `payments_settlements` rows, and only then post any ledger movement.
- **Idempotent on `order_id`,** which the partial unique index already enforces.
- **Reversible and counted,** per table and column, in the manner of the
  timezone backfill's log table.
- **Dry-runnable,** with counts compared before the first write.
- **Must not double-count.** An order already settled through the legacy path
  must not also produce a settleable accrual. Double-counting historical
  payables is the worst outcome available here, and it is why the legacy tables
  are retained read-only rather than migrated.

Until that work is done, M27 settles only orders accrued *after* the accrual
flag is switched on. That is a correct and safe state, not a partial one.

---

## 9. Explicitly not done

Recorded here so a later reader cannot mistake a scoped-out item for a finished
one. Nothing on this list is a defect.

**Merchant bank details are not stored.** There is no merchant payout-destination
store, so a bank transfer requires the destination on the execute call, exactly
as the legacy path did. Settling to the merchant's **wallet** — the default when
no bank account is supplied — is fully implemented and is the safer path
(atomic, no provider, no unknown outcome). Building a bank-details store is
KYC-adjacent, needs encryption and verification, and belongs in its own change.

**Operations notifications have no recipients.** `reconciliationRequired()` and
`caseOpened()` log at warning level. The platform has no operations notification
group, and inventing a recipient list here would pick people nobody agreed to.
The reconciliation queue is the real surface; the operator dashboard reads the
logs.

**Provider transfer-status endpoints are configuration-driven and unconfigured.**
`AbstractHttpGateway::fetchTransferStatus()` queries a configured
`transfer_status_path`. No provider has one configured, so every real provider
currently answers `Unknown` — which never resolves a case and escalates to a
human. That is the safe failure, not a silent one, and it is why `Unknown` is
not `Failed`. Configuring the real endpoints per provider is deployment work.

**No FX, no cross-currency settlement.** Rejected, not converted. CHECK
constraints and domain guards refuse mixed currencies outright.

**Disputes and chargebacks are M28.** A settlement can be held; adjudicating one
is not in scope.

**`settlement.auto_approve` has no implementation behind it.** Declared so the
switch is visible and visibly off.

---

## 10. What a reviewer should check first

1. `PayableAccrualService::captureFigures()` — that the amounts come from the
   ledger and from nowhere else.
2. `SettlementRunState::allowedNext()` — that `Unknown` leads only to
   `Reconciling`.
3. `payments_settlement_lines`'s unique index on `accrual_id`.
4. `SettlementRun::beginExecution()` — the four-eyes refusal.
5. `routes.php` — that no mutating route sits under `finance.read`.
6. `scripts/m27_negative_control_audit.php` — run it; every protection above
   should fail its test when removed.

---

## 11. The concurrency gate must run against the offline provider

The first CI run of this branch reported `51 passed, 6 failed` on the financial
concurrency step, while the same commit gave 57/57 locally. Both runs were real;
the environments differed, and the difference mattered.

**Why.** CI copies `.env.example`, which sets `APP_ENV=local`. Under that,
`config('payments.default')` resolves to `paystack`, not the offline mock — so
the harness was issuing **real bank transfer requests to a live provider
endpoint** with no credentials. Every settlement scenario that moves money
through a provider failed, and the failures looked like concurrency defects.
They were not. Locally the runs passed only because `APP_ENV=testing` was
exported, which forces the mock.

`MOCK_GATEWAY_TRANSFER_OUTCOME` — the cross-process hook that makes scenarios 17
and 18 simulate a timeout — was never read for the same reason: `MockGateway`
was never instantiated. That is the whole of the "CI is not receiving the
intended UNKNOWN outcome" question.

**Two fixes, because there were two problems.**

1. **The harness now refuses to start** unless the resolved gateway is the
   offline mock, naming what it resolved and how to correct it. A refusal, not a
   silent override: forcing the mock internally would have hidden the
   misconfiguration and left the gate quietly meaningless. Both workflows that
   run it now pin `PAYMENTS_PROVIDER=mock` explicitly.
2. **The adapters were genuinely wrong**, and the harness only revealed it.
   `transfer()` on all five HTTP providers now routes through
   `AbstractHttpGateway::transferResult()`: a 2xx is `Processing`, and every
   other status *and every thrown exception* is `Unknown`. A bare HTTP status is
   not evidence about what the provider did — proxies and WAFs emit 4xx, a 409
   often means the transfer already exists — so only the reconciler, which asks
   the provider directly, may conclude a transfer did not happen.

The second fix is a production defect closed, not test scaffolding. Before it,
a timed-out payout to a real merchant through a real provider would have been
marked retryable.
