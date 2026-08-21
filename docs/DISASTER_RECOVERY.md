# EruoFood AI — Disaster Recovery

Recovery from the loss of a whole availability zone or region, or a
platform-wide compromise. Builds on `docs/BACKUP_RESTORE.md`.

## 1. Objectives

| Tier | Scenario | RTO | RPO |
|---|---|---|---|
| 1 | Single AZ loss | ≤ 15 min (automatic) | 0 (sync replica) |
| 2 | Region loss | ≤ 2 hours | ≤ 5 min (async WAL) |
| 3 | Account/tenant compromise | ≤ 4 hours | ≤ 5 min |

## 2. Topology assumptions

- **Multi-AZ** by default: API/worker pods across ≥2 AZs; PostgreSQL primary with
  a synchronous standby in another AZ; Redis with a replica in another AZ.
- **Cross-region**: base backups + WAL + object-storage replication continuously
  shipped to a second region. Infrastructure is reproducible from
  `infra/terraform/` (IaC), so the standby region can be stood up from code.

## 3. Tier 1 — Availability-zone failure (automatic)

- Managed PostgreSQL fails over to the standby (seconds–minutes); app reconnects
  via the endpoint (no config change).
- Redis fails over to its replica.
- Kubernetes reschedules pods onto healthy AZs; readiness gates traffic.
- **Action:** verify failover completed; confirm health/readiness; no data action.

## 4. Tier 2 — Region loss (manual, runbook)

1. **Declare** a DR event (`docs/INCIDENT_RESPONSE.md`), assign an Incident
   Commander.
2. **Provision** the standby region from IaC (`terraform apply` against the DR
   workspace) if not warm-standby.
3. **Database:** promote the cross-region replica, or restore the latest base
   backup + replay WAL to the last shipped point (RPO ≤ 5 min).
4. **Object storage:** point the app at the replicated bucket.
5. **Secrets:** rehydrate env from the secret manager's replicated copy.
6. **DNS cutover:** repoint `api.eruofood.ai` / `app.eruofood.ai` to the DR
   region's load balancer (low TTL records kept for fast cutover).
7. **Migrations:** confirm schema head with `php artisan migrate:status`.
8. **Verify** with the smoke suite and the critical-flow checks; open traffic.

## 5. Tier 3 — Compromise / ransomware

- Isolate: revoke credentials, rotate **all** secrets (`INCIDENT_RESPONSE.md`
  §Secret rotation), disable the affected accounts.
- Restore into a **clean** account/project from immutable, versioned backups
  (object-lock protects against deletion by an attacker).
- Forensics on the compromised environment before any teardown.

## 6. Dependencies & degradation

| Dependency | On failure |
|---|---|
| Payment processor | Order placement continues; capture/settlement queued and retried; wallet unaffected |
| Mail provider | Notifications queue and retry; no data loss |
| Object storage | Media uploads degrade; core transactional flows unaffected |
| Redis | Rate-limit/quota fail-open briefly; queues from AOF/replica |

## 7. Testing

- **Quarterly** region-failover game day into the DR workspace (non-prod),
  measuring actual RTO/RPO and updating this document with the results.
- **Monthly** database restore drill (see `BACKUP_RESTORE.md` §1).

## 8. Communication

- Status page updated within 15 min of a declared incident.
- Stakeholder + customer comms per severity (`INCIDENT_RESPONSE.md`).

---

## Cross-cutting foundation — drill re-executed on the current schema

The Milestone 21 result below covered 105 tables and predates M24, M25 and M26.
It was re-run against the current schema: **126 tables / 519 indexes** dumped,
database dropped and verified empty, restored to **127 tables / 520 indexes**
with the marker row intact and **140 migrations ran, 0 pending** —
**EXECUTED — PASSED**. See `BACKUP_RESTORE.md`.

Region failover (Tier 2) and PITR remain deployment-time validations.

## Milestone 21 — backup/restore drill executed

The database backup/restore leg of DR was **executed** (see `BACKUP_RESTORE.md` →
"Executed backup/restore drill"): `pg_dump -Fc` → `DROP DATABASE` → `pg_restore`
round-tripped **105 tables / 406 indexes / all rows** identically on PostgreSQL 16,
with the migration head intact — **EXECUTED — PASSED**. Region-failover (Tier 2)
and PITR remain deployment-time validations against managed infrastructure.

## Financial safety during an outage

The sections above restore *service*. This section is about what must be true of
the *money* while service is degraded — a different question, with a different
failure mode: an outage that costs an hour of availability is recoverable, and
one that pays a merchant twice is not.

### The rule that governs every case below

> An interrupted transfer has an **unknown** outcome, not a failed one.

Nothing in the platform may turn an unknown into a retry. `GatewayOutcome::Unknown`
is not `isSafelyRetryable()`, `SettlementRunState::Unknown` has exactly one
outgoing transition — to `Reconciling` — and the aggregate has no `retry()` from
it. During an incident this is the rule people will want to break, because the
merchant is on the phone and the obvious fix is to try again. Trying again is how
they get paid twice.

### Per-failure posture

| Failure | What happens to money | Do this |
|---|---|---|
| **Application pods down** | Nothing in flight; no payout starts without a person | Restore service. No financial action. |
| **Database unavailable** | Payouts cannot be recorded, so none are attempted — the payout attempt is written *before* the provider is called | Restore per §Database. Then run `scripts/restore_verification.php` if a restore was involved. |
| **Database restored from backup** | Attempts made after the backup point are lost from our records but may have happened at the provider | **Do not settle** until reconciliation has run against the provider for the gap window. Assume every settlement run in the window has an unknown outcome. |
| **Redis / queue lost** | Queued financial work is lost, not duplicated; job side-effects are idempotent | Restore. Re-run reconciliation; do not re-run settlement. |
| **Payment provider outage** | Every transfer attempted during the window resolves to UNKNOWN by design | Set `FLAG_SETTLEMENT_EXECUTE=false` to stop creating unknowns faster than reconciliation can clear them. Leave in-flight attempts alone. |
| **Provider reachable but ambiguous** (5xx, timeouts, 4xx from a proxy) | Same as an outage: UNKNOWN | Never conclude a decline from a transport error. Only `fetchTransferStatus()` may say a transfer did not happen. |
| **Object storage lost** | No financial impact; KYC/KYB evidence at risk | Restore from versioning/replication. |
| **Region loss** | As database-restored, plus provider state unknown | Full reconciliation before the first payout in the recovered region. |

### The kill switch is the first move, not the last

`FLAG_SETTLEMENT_EXECUTE=false` needs no deploy and no migration. It stops new
payout attempts being created and **leaves attempts already submitted alone** —
deliberately, because abandoning an in-flight transfer is precisely how its
outcome becomes unknown. Its behaviour under load is covered by
`KillSwitchValidationTest`, including that it stops paying mid-flight.

### Recovering from unknown outcomes

1. Confirm `settlement.reconcile` is enabled and the provider's
   `transfer_status_path` is configured — an adapter that cannot ask returns
   UNKNOWN forever, and the sweep will look like it is running while resolving
   nothing.
2. Let the reconciler ask the provider about each outstanding reference. It
   closes only what the provider confirms.
3. Anything the provider cannot answer stays open as a reconciliation case for a
   human. The reconciler never guesses, and never corrects the books itself.
4. Only once the case list is empty for the affected window may
   `settlement.execute` be re-enabled.

### What has not been drilled

Stated plainly rather than implied: the financial postures in this table are
**documented and unit-tested, not rehearsed against real infrastructure**. A
provider-outage game day and a restore-gap reconciliation drill both remain
outstanding, and are preconditions for the first live payout rather than for
this milestone.
