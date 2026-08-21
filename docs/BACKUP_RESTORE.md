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

---

## Executed backup/restore drill (cross-cutting foundation) — EXECUTED — PASSED

The Milestone 21 drill above covered **105 tables / 406 indexes** and predates
M24, M25 and M26. It no longer describes the schema in production, so it was
re-executed against the **current** schema rather than being cited.

| Step | Command | Result |
|---|---|---|
| Schema + data | `migrate:fresh` + a marker table | **126 tables, 519 indexes** |
| Backup | `pg_dump -Fc` | 324 KB custom-format dump |
| Destroy | `DROP DATABASE` + `CREATE DATABASE` | verified empty — **0 tables** |
| Restore | `pg_restore` | exit 0, no errors |
| Verify | table/index/row counts, marker value, `migrate:status` | **127 tables, 520 indexes** (126 + marker), marker value identical, **140 migrations ran, 0 pending** |

Round-tripped identically on PostgreSQL 16 with the migration head intact.

**What this drill does and does not certify.** It certifies that a full logical
backup of the current schema restores completely into an empty database, which
is the leg that can be executed here. Region failover (DR tier 2) and
point-in-time recovery via WAL replay remain deployment-time validations against
managed infrastructure — they cannot be exercised in a single-node container,
and this document does not claim otherwise.


## 5. Financial and audit records — what a restore must additionally prove

Sections 1–4 cover getting the bytes back. For a platform that moves merchant
money, that is the easy half. `pg_restore` exiting zero says nothing about
whether the ledger still balances, whether a settlement line survived without
the run it belongs to, or whether the constraints that make a second payout
structurally impossible came back with the data. A restore that quietly dropped
a unique index looks exactly like a good one — until the first duplicate payout.

### Additional backup scope

These are covered by the PostgreSQL backup above, and are called out because
their **retention** is driven by finance and compliance rather than by RPO:

| Records | Tables | Retain |
|---|---|---|
| Payable accruals | `payments_payable_accruals` | 7 years (financial record) |
| Settlement runs and lines | `payments_settlement_runs`, `payments_settlement_lines` | 7 years |
| Payout attempts | `payments_payout_attempts` | 7 years |
| Reconciliation cases | `payments_reconciliation_cases` | 7 years |
| Double-entry ledger | `payments_ledger_entries` | 7 years, append-only |
| Privileged action audit | `admin_audit_log` | 7 years, append-only |

The two append-only tables carry database triggers that refuse `UPDATE` and
`DELETE`. Those triggers are part of the backup and must come back with it —
see the verification below.

### Verification — `scripts/restore_verification.php`

Run against the **restored copy**, never production:

```bash
DB_DATABASE=<restored-copy> php scripts/restore_verification.php
```

It is read-only and exits non-zero on any failure. It asks six questions:

1. **Schema head** — migrations recorded, every financial table present, all
   five M27 migrations applied.
2. **Readability** — application data comes back, not just the schema.
3. **Referential integrity** — no orphaned settlement line, no orphaned payout
   attempt, no ledger entry without a correlation id.
4. **Financial consistency** — every accrual and run's net equals gross minus
   commission minus fee; every run's net equals the sum of the lines it reserved.
5. **Ledger integrity** — the book nets to zero, *and every correlation balances
   on its own*. A ledger can net to zero while individual events are broken,
   because two opposite errors cancel.
6. **Duplicate-payout safety** — no accrual on two lines, no run with two
   settled attempts, and the unique indexes, CHECK constraints and append-only
   triggers that guarantee it are all still present.

Point 6 is the one that distinguishes this from a smoke test. The data being
clean today is not the guarantee; the constraint is.

### Drill record

| | |
|---|---|
| Date | 2026-08-21 |
| Source | `eruofood_concurrency` — 11 accruals, 7 settlement runs, 7 lines, 3 payout attempts, 74 ledger entries |
| Method | `pg_dump --format=custom --no-owner` → 364 KB |
| Target | `eruofood_restore_drill`, created empty |
| Dump duration | 1.09 s |
| Restore duration | 1.21 s |
| Verification | **25 checks passed, 0 failed** (0.07 s) |
| Environment | Non-production. No production data was involved. |

**Negative controls.** A verifier that passes on a broken restore is worse than
none, so the drill also damaged copies of the restored database and confirmed
each check bites:

| Damage | Caught by |
|---|---|
| Unique constraint on `settlement_lines.accrual_id` dropped | duplicate-payout safety |
| Settlement run deleted | referential integrity (2 orphaned lines) |
| Accrual deleted | referential integrity (1 orphaned line) |
| Ledger amount altered | ledger integrity (net=1, 1 unbalanced correlation) |
| Accrual figures made inconsistent | financial consistency |
| Four-eyes CHECK dropped | duplicate-payout safety |
| Append-only trigger dropped | duplicate-payout safety |

Two of the intended controls **could not be applied at all**: the append-only
trigger refused the ledger `UPDATE`, and the `net_derived` CHECK refused the
inconsistent accrual. That is the protection working. It is also what prompted
adding the append-only trigger check — a restore that lost those triggers would
leave the ledger rewritable with nothing to say so.

### Limitations of this drill

Stated so the record is not read as more than it is:

- It restored a **non-production database of test data**, not a production
  backup. The RTO figures above are therefore not a production RTO.
- It exercised **logical restore** (`pg_dump`/`pg_restore`). Point-in-time
  recovery from WAL, which is what delivers the ≤5 min RPO, has **not** been
  drilled — it needs the managed instance and is a deployment-time exercise.
- Object storage and Redis restore were not exercised.
- The monthly drill in §1 remains required, and must be run against a real
  production backup before the first live payout.
