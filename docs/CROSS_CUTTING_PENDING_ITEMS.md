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

---

## B. Settlement and subscription creation remain non-idempotent

**Status:** known gap, deliberately not closed.

`POST /api/v1/payments/settlements` and `POST /api/v1/payments/subscriptions`
have no idempotency scope. A double submission can create duplicate work.

**Why not now:** settlement is M27-adjacent, and this foundation must not begin
M27. Both are low-frequency operator or lifecycle paths rather than
customer-facing taps, so the exposure is materially smaller than the payment
initiation gap that *was* closed.

**Pending:** close both in the milestone that owns payment orchestration. The
scope list in `IdempotencyCoverageTest` is the contract — adding a scope there
without implementing it fails the suite, and vice versa.

---

## C. `RetentionRegistry` is declarative only

**Status:** the declaration exists; **nothing enforces it**.

`RetentionRegistry::platformDefaults()` states, for six categories of data, the
purpose, retention period, deletion mode, access policy and audit requirement.
It is the answer to "what do we keep and why", in one place instead of scattered
across three config files.

**It deletes nothing.** There is no purge job, no scheduled deletion and no
anonymisation routine. The `lifecycle.retention_purge` flag exists and is off;
there is no code behind it.

**Why not now:** deletion is the one operation on this list that nobody can
undo. `DeletionMode::isReversible()` returns true only for `Archive`. A purge
must run in dry-run mode for a full cycle, with counts compared per category,
before it is ever allowed to act — and that cycle has not happened.

**Pending:** the purge implementation, its dry-run mode, and the drill that
precedes first destructive use. Until then, data past its declared retention is
**still present**. The registry describes intent, not current state.

---

## D. `RetryQueue` has no transport

**Status:** decision and recovery logic, verified. **No networking.**

`RetryQueue` decides what may be retried, what must be reconciled first, and
what must simply wait. It persists across restarts through a
`PendingOperationStore` interface. All of that is tested, including negative
controls (see below).

**What it does not do:** send anything. There is no Dio interceptor, no
`PendingOperationStore` implementation backed by real storage, and no feature in
`apps/mobile` currently enqueues through it.

**Why not now:** wiring it into a transport means choosing per-feature adoption
rules, which is mobile feature work — explicitly out of scope alongside M34
release engineering.

**Pending:** a Dio-backed sender, a persistent store implementation, and
per-feature adoption. Until those exist, **no mobile feature is protected by
this queue**, and the foundation must not be described as giving the app offline
resilience. It gives the app the *means* to have it.

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
