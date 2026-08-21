# EruoFood AI — Rollback Plan

Fast, safe reversal of a production release. Because migrations follow the
**expand/contract** rule (`docs/PRODUCTION_DEPLOYMENT.md` §4), the previous app
version is always schema-compatible with the current database, so a code rollback
does **not** require a database rollback in the normal case.

## 1. Decision criteria (roll back if, during the bake period)

- Error rate > 1% sustained, or a spike in 5xx.
- p95/p99 latency beyond `PERFORMANCE_REPORT.md` thresholds.
- Readiness failing on a material fraction of pods.
- A functional regression confirmed in a critical flow (auth, orders, payments).
- Any data-integrity anomaly.

Anyone on-call may call a rollback; no approval needed to protect availability.

## 2. Code rollback (default, fast — target < 5 min)

The previous image digest is retained and pinned. Roll deployments back to it:

```bash
# Re-point to the previous known-good digest (kept in the deploy history)
kubectl rollout undo deploy/api
kubectl rollout undo deploy/worker
kubectl rollout undo deploy/web
kubectl rollout status deploy/api --timeout=180s
```

Because the release was **expand-only**, the older code runs correctly against the
expanded schema. No migration rollback is required. Verify with the §5 checklist.

## 3. When a migration must be reversed

Only if the release included a **non-additive** change (it should not — that
violates expand/contract). If it did and the change is harmful:

```bash
# Reverse exactly the migrations from this release (know the count/batch first)
php artisan migrate:rollback --step=<N> --force
```

- `down()` methods exist for every migration and are exercised in CI
  (`migrate:rollback` runs on every API build).
- If a migration was **destructive** (dropped/renamed a column with data), code
  rollback is not enough — restore from backup for the affected tables using
  `docs/BACKUP_RESTORE.md` (point-in-time recovery to just before the deploy).

## 4. Data considerations

- Rows written by the new version during the bake period use only **additive**
  columns, so they remain valid under the old version.
- Queue jobs: drain or let workers finish; jobs are idempotent (idempotency keys
  on payment/order side-effects), so re-processing after rollback is safe.
- Caches: `php artisan cache:clear` + config/route cache rebuild on the rolled-back
  image (baked at build).

## 5. Post-rollback verification

- Health + readiness green on all replicas.
- Error rate and latency back to baseline.
- Critical smoke tests pass:
  ```bash
  curl -fsS https://api.eruofood.ai/api/v1/health
  curl -fsS https://api.eruofood.ai/api/public/v1/status
  ```
- Open an incident record (`docs/INCIDENT_RESPONSE.md`) and capture the root
  cause before attempting re-release.

## 6. Roll-forward alternative

If the fault is small and understood, a **roll-forward** patch release (hotfix
tag through the same gated pipeline) can be preferable to a rollback — decided by
on-call based on blast radius and time-to-fix.

## 7. Rolling back a milestone merge (M27 settlement baseline)

The sections above roll back a *release*. This rolls back a *merge*, which is
what you want when a milestone has landed on `main` but nothing has been
deployed from it yet.

**M27 verified settlement baseline**

| | |
|---|---|
| Merge commit | `8a2a2e8d90e51f45f00be56316304f1788621c55` |
| `main` before the merge | `fc412dcd9721fd9d3fb48f4b81f804819bdf014f` |
| Branch merged | `claude/m27-settlement` @ `77a8526`, retained on origin |
| Revert | `git revert -m 1 8a2a2e8` — restores tree `090e6542`, identical to `fc412dc` |

`-m 1` is not optional: it selects the first parent, which is the pre-merge
`main`. Reverting the wrong parent keeps M27 and discards everything `main` had.

**No database rollback is required.** M27 is expand-only: all five of its
migrations create new tables and none alter or drop an existing one, so the
pre-M27 code runs unchanged against the post-M27 schema. The new tables simply
stop being written to.

**What a revert does not undo.** Nothing, currently — every settlement flag is
off, so no accrual, run, payout or ledger posting from M27 exists in production.
That stops being true the moment `settlement.accrual_posting` is enabled: from
then on, reverting the code leaves accrual rows and `MerchantPayable` postings
behind, and removing those is a compensating posting rather than a revert.
Before enabling that flag, this section needs a data-rollback plan; a code revert
alone is no longer sufficient.

**Prefer the flag to the revert.** For anything financial, disabling
`FLAG_SETTLEMENT_EXECUTE` is faster, needs no deploy, and leaves in-flight
transfers to be reconciled rather than abandoned — which is the difference
between a payout with a known outcome and one with an unknown one.
