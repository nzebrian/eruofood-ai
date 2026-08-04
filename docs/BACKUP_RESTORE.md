# EruoFood AI — Backup & Restore

Covers PostgreSQL (system of record), Redis (ephemeral/derived), and object
storage (media). Targets: **RPO ≤ 5 minutes**, **RTO ≤ 60 minutes** for the
database (see `docs/DISASTER_RECOVERY.md` for the full-region case).

## 1. PostgreSQL (authoritative data)

### Backup
- **Continuous PITR:** WAL archiving enabled on the managed instance (or
  `pg_receivewal` to object storage) → any-point-in-time recovery within the
  retention window. This delivers the ≤5 min RPO.
- **Daily base backup:** automated snapshot / `pg_basebackup`, retained 30 days.
- **Weekly logical dump** for portability and partial restores:
  ```bash
  pg_dump --format=custom --no-owner --dbname="$DATABASE_URL" \
    | aws s3 cp - "s3://eruofood-backups/pg/$(date +%F)/eruofood.dump"
  ```
- Backups are **encrypted at rest** (SSE-KMS) and stored in a separate account/
  project from production. Access is least-privilege and audited.

### Restore
```bash
# Full logical restore (new/empty target)
aws s3 cp "s3://eruofood-backups/pg/<date>/eruofood.dump" - \
  | pg_restore --no-owner --clean --if-exists --dbname="$TARGET_DATABASE_URL"

# Point-in-time recovery (managed): restore snapshot + replay WAL to a timestamp
#   (e.g. one second before a bad deploy/migration), then repoint the app.
```
After restore: run `php artisan migrate:status` to confirm schema head, then the
readiness probe.

### Verification (non-negotiable)
- **Monthly restore drill:** restore the latest backup into an isolated
  environment, run `php artisan migrate:status` + a smoke suite, record the RTO
  achieved. A backup is not "valid" until a restore has been proven.

## 2. Redis (cache, queues, rate-limit/quota counters)

Redis holds **derived/ephemeral** state — it is **not** a backup target for
correctness. On loss:
- Cache repopulates on demand.
- Rate-limit/quota counters reset to zero (fail-open to availability; acceptable
  for a short window).
- **Queues:** enable AOF (`appendonly yes`, `everysec`) so in-flight jobs survive
  a restart; managed Redis with replication + daily RDB snapshot is sufficient.
  Job side-effects are idempotent, so at-least-once redelivery is safe.

## 3. Object storage (media, exports)

- Bucket **versioning** enabled + lifecycle rules; cross-region replication for DR.
- Deletes are soft (versioned) with a 30-day recovery window.
- No application-side backup needed beyond replication.

## 4. Encryption & key custody

- All backups encrypted with KMS-managed keys; key rotation annually.
- Losing the KMS key = losing the backups → keys are multi-Region / escrowed per
  the provider's guidance.

## 5. What is NOT backed up (by design)

- Compiled caches (`config`/`route`/`event`) — rebuilt at image build.
- Local container filesystems — treated as disposable.

## 6. Restore runbook summary

| Scenario | Action | Target |
|---|---|---|
| Bad migration / deploy | PITR to just-before, or code rollback (see ROLLBACK_PLAN) | RTO ≤ 60 min |
| Table-level corruption | Logical restore of affected tables | RTO ≤ 60 min |
| Full DB loss | Restore latest base backup + WAL replay | RTO ≤ 60 min, RPO ≤ 5 min |
| Region loss | See `docs/DISASTER_RECOVERY.md` | RTO per DR tier |

---

## Executed backup/restore drill (Milestone 21) — EXECUTED — PASSED

A real PostgreSQL backup → simulated-loss → restore drill was performed against
PostgreSQL 16 (the production engine):

| Step | Command | Result |
|---|---|---|
| Schema + data | `artisan migrate` + seed marker rows | 104 migration tables + a 2-row marker table = **105 tables, 406 indexes** |
| Backup | `pg_dump -Fc -f eruofood.dump` | 242 KB custom-format dump in **0.12 s** |
| Simulate loss | `DROP DATABASE` + `CREATE DATABASE` (empty) | database recreated empty |
| Restore | `pg_restore --no-owner eruofood.dump` | completed in **0.60 s** |
| Verify | table/index/row counts + data value + `migrate:status` | **105 tables, 406 indexes, marker rows intact, note value identical, migration head present** |

**Result: PASS** — schema, indexes, and data round-tripped identically; the
migration head was intact after restore. This validates the `pg_dump`/`pg_restore`
procedure end-to-end. Production adds PITR (WAL replay) on top for the ≤5 min RPO
(managed-instance feature, not exercisable in this sandbox).
