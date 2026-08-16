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
