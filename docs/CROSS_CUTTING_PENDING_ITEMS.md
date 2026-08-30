# Cross-cutting foundation — explicitly pending items

Everything on this page is **deliberately incomplete**. Each entry exists so
that a later reader cannot mistake a partial capability for a finished one, and
so nobody re-derives from scratch a decision that was already made on purpose.

Nothing here is a defect. They are scoped-out items with a stated reason.

---

## A. `payments.initiate` idempotency is opt-in

**Status:** implemented, but only protects callers that use it.

The coverage audit found `POST /api/v1/payments` unguarded while checkout,
wallet top-up, wallet transfer, refund and dispatch acceptance were all
protected. A client that timed out and retried opened a **second payment intent
for the same order**, and the customer could complete both.

It is now wrapped in the `payments.initiate` idempotency scope — but the key is
**optional**, exactly as it is on refunds. Sending `Idempotency-Key` makes a
retry replay the original intent (200); omitting it preserves the previous
behaviour.

**Why opt-in:** making it mandatory would break every existing caller on the
deploy that shipped it. The protection is available now; adoption is a client
change.

**Pending:** make the key mandatory once all first-party clients send it. Until
then a caller that omits it is unprotected, and no test can assert otherwise.

**This applies to `payments.initiate` only.** `payments.subscription` (§B)
*requires* the header, and that is not a departure from the rule above but an
instance of it: the M41 pre-merge audit traced every caller and found **no
first-party client of the subscription endpoint at all** — nothing in
`apps/web`, nothing in `apps/mobile` (its retry queue declares four scopes, none
of them subscriptions), and no job, command, seeder, script or internal service.
`SubscriptionController::store()` is the only production caller of
`SubscriptionService::start()`. The precondition therefore holds vacuously
there, while `payments.initiate` is called by the mobile app and stays opt-in
until that client sends the header. The audit could not prove the absence of
third-party consumers outside the repository; that residual risk is the reason
this distinction is written down rather than assumed.

---

## B. Subscription creation — CLOSED by M41

**Status:** guarded, and the key is **mandatory** here. See
[`api/payments-endpoints.md`](api/payments-endpoints.md).

This entry previously read *"`POST /api/v1/payments/subscriptions` has no
idempotency scope. A double submission can create duplicate work"*, and argued
the gap was tolerable because subscriptions are *"a low-frequency lifecycle path
rather than a customer-facing tap, so the exposure is materially smaller than
the payment initiation gap"*. **That reasoning was wrong**, and is recorded here
rather than quietly deleted: frequency is the wrong measure for a standing
instruction. A duplicate payment is one extra charge the customer can see and
dispute; a duplicate subscription is one extra charge *every billing period*,
and two identical subscriptions for one user are indistinguishable from a
customer who wanted two — so no later reconciliation can separate them. Low
frequency made the gap rarer, not smaller.

What closed it:

- `payments.subscription` is now in the `IdempotencyCoverageTest` scope list,
  which §A of this page and that test both treat as the contract.
- The claim reuses the existing `IdempotencyStore` and `shared_idempotency_keys`
  table; the correctness boundary is the existing `unique(scope,
  idempotency_key)` index, not a read-then-write check. No new table, no
  migration, and the index was deliberately **not** widened to include the
  nullable `user_id` — PostgreSQL treats nulls in a unique index as distinct, so
  that would have removed the guarantee from every scope that predates M41.
- The stored key is `sha256(principalId . "\0" . rawClientKey)`, so the
  constraint is per-principal without touching the index: two users may use the
  same key value independently, and neither can reach the other's record.

**Settlement was closed by M27**, though not with an idempotency scope. The new
path (`POST /api/v1/payments/admin/settlement-runs`) is protected by a partial
unique index on `(merchant, currency, window)` for live runs, which is stronger:
it holds regardless of whether the caller supplies a key. An `Idempotency-Key`
header is honoured additionally when one is sent.

---

## C. `RetentionRegistry` enforcement — enforceable since M42, and switched off

**Status:** every declared policy now has an enforcement path or a written
reason it has none. **Nothing runs unattended**: every retention schedule is
registered `enabled: false`, and `lifecycle.retention_purge` is off.

This entry previously read *"there is no purge job, no scheduled deletion and no
anonymisation routine"*. **Two-thirds of that was wrong when it was written**,
and it is corrected here rather than quietly deleted: `search:purge-query-log`
has existed since M40 and `verification:purge` since M24. Both were real,
working purge commands. What was missing was not the code but the reach —
neither was registered as a scheduled task, so a declared window was enforced
only if somebody typed the command. A page that says "no purge job" when two
exist teaches the next reader to distrust the page, which is worse than the gap
it was describing.

### What each policy's enforcement is

| Policy | Window | Mode | Enforcement |
| --- | --- | --- | --- |
| `shared.idempotency_keys` | 1 day | Destroy | `shared:purge-idempotency-keys` (M42) |
| `geo.rider_locations` | 30 days | Destroy | `geo:purge-rider-locations` (M42) |
| `search.query_log` | 90 days | Destroy | `search:purge-query-log` (M40) |
| `verification.identity_documents` | 1825 days | Destroy | `verification:purge` (M24) |
| `notifications.sent` | 365 days | Anonymise | **none — see below** |
| `payments.ledger` | 2555 days | Archive | none; nothing is due for seven years |
| `admin.audit_entries` | 2555 days | Archive | none; an audit trail cannot be anonymised |

`RetentionEnforcement` holds that mapping, and it is not a second registry — it
carries no windows, modes or categories. `for()` **throws** on a key that is in
neither column, and `RetentionEnforcementTest` walks `RetentionRegistry` itself
rather than a copied list, so a policy added later cannot arrive unenforced and
unnoticed.

### The one gap M42 did not close

`notifications.sent` declares `DeletionMode::Anonymise`, and there is no
anonymisation mechanism anywhere in this codebase to reuse. "The record is kept
with the person removed from it" would mean clearing `user_id`, `subject`,
`body`, `data` and `timeline` on `notifications_notifications` — and every one
of those columns is `NOT NULL`. Honouring the mode needs either a schema
migration making `user_id` nullable or a sentinel-value convention that does not
exist here, and M42 was scoped to exclude schema changes. So it is recorded as a
documented exemption rather than guessed at.

**Converting the policy to `Destroy` would be the wrong fix**, and the negative
controls fail if anybody tries: the purpose — show somebody what we sent them —
survives anonymisation and does not survive deletion.

### Both locks, and why there are two

Deletion is the one operation on this list nobody can undo;
`DeletionMode::isReversible()` is true only for `Archive`. So a destructive task
passes two independent switches before it can run unattended:

- `enabled: false` on the task, which lives with the module that registered it.
- `destructiveRetention: true`, which subjects it to `lifecycle.retention_purge`
  — the master flag, `safeDefault: false`, which lives with the operator who
  owns the database.

`bootstrap/app.php` skips a `destructiveRetention` task whenever the flag is
closed, so either switch alone stops a run. A task flipped on by accident still
does nothing.

### Before the first destructive run

Unchanged from the original entry, and still not optional: each command must run
under `--dry-run` for a full cycle, with counts compared per category, before it
is allowed to act. The dry run reports eligibility and returns without touching
a row. Until an operator does that and then deliberately opens both locks, data
past its declared retention is **still present** — the difference M42 makes is
that removing it is now a decision somebody takes, rather than a capability
nobody has.

Two details worth carrying into that drill:

- **Idempotency eligibility is `expires_at`, never `created_at`.** A claim's age
  is not its eligibility. `shared:purge-idempotency-keys` therefore has no
  `--days` option at all: deleting a live claim would reopen the
  duplicate-payment window that claim exists to close.
- **The commands print counts and timestamps only** — never keys, request
  hashes, response snapshots, coordinates, rider ids or user ids. They exist
  because those values should not persist; echoing them into a terminal, and
  from there into a CI log, on the way to deleting them would defeat the point.

---

## D. `RetryQueue` transport — CLOSED by M30-D

**Status:** connected. See [`MOBILE_RETRY_QUEUE.md`](MOBILE_RETRY_QUEUE.md).

This entry previously read *"no Dio interceptor, no `PendingOperationStore`
implementation backed by real storage, and no feature in `apps/mobile` currently
enqueues through it"*. All three are now false:

- `RetryQueueInterceptor` sits on the Dio instance every feature data source
  already uses, so interception is a property of the transport rather than
  something each feature has to remember.
- `SecurePendingOperationStore` persists the queue in the same Keychain/Keystore
  `TokenStore` uses.
- `RetryQueueProcessor` reconciles against `POST /reconcile` and resends only
  what the server says is safe to resend.

Commerce checkout is proven end to end through the production repository, data
source, client and interceptor in
`test/features/commerce/commerce_retry_queue_test.dart`.

**What is still deliberately narrow:** four endpoints are declared —
`commerce.checkout`, `marketplace.checkout`, `payments.initiate` and
`payments.wallet.topup`. Those are the only mobile calls the server guards with
an idempotency scope. Three server scopes (`payments.refund`,
`payments.wallet.transfer`, `dispatch.accept`) have no mobile caller and are not
declared; nothing else is queued at all. **A feature outside that list is not
protected by this queue**, and the app must not be described as generally
offline-resilient.

**Pending:** an event-driven trigger. Replay runs on app resume and on
authentication, because the project has no connectivity package and adding one
would be a lockfile change. A device that regains signal while the app is
already open and authenticated waits for the next resume.

---

## E. Region failover and PITR are not executed

**Status:** documented procedures, **not exercised**.

The backup/restore drill in `docs/BACKUP_RESTORE.md` was re-executed against the
current schema: 126 tables and 519 indexes dumped, database dropped and verified
empty, restored to 127 tables and 520 indexes with the marker row intact and 140
migrations ran, 0 pending. That result is real and repeatable.

**It certifies one thing:** a full logical backup of the current schema restores
completely into an empty database.

**It does not certify:**

- **Region failover (DR tier 2)** — promoting a cross-region replica, DNS
  cutover, standing the standby region up from IaC.
- **Point-in-time recovery** — WAL replay to an arbitrary instant, which is what
  the stated RPO of ≤ 5 minutes actually depends on.

**Why not now:** neither can be exercised in a single-node container. They
require managed infrastructure with cross-region replication configured, and
they are deployment-time validations by nature.

**Pending:** both, as part of a deployment readiness exercise. The RPO/RTO
figures in `docs/DISASTER_RECOVERY.md` are **targets, not measurements**, until
that happens.

---

## F. M27 settlement leaves three things to deployment

Recorded in full in `docs/M27_SETTLEMENT_REPORT.md` §9; summarised here so this
page stays the single index of what is deliberately incomplete.

1. **Merchant bank details are not stored.** A bank payout needs the destination
   supplied on the execute call. Settling to the merchant's wallet is complete
   and is the default.
2. **Provider transfer-status endpoints are unconfigured.** Every real provider
   therefore answers `Unknown` to a reconciliation query, which escalates to a
   human rather than resolving anything. That is the safe failure; configuring
   the per-provider paths is deployment work.
3. **Historical accruals are not backfilled.** M27 settles only orders accrued
   after the flag is switched on. The backfill's requirements — reporting-only
   first, idempotent, reversible, counted, dry-runnable, and above all not
   double-counting orders already settled through the legacy path — are written
   down in the report, and the migration is deliberately unwritten.
