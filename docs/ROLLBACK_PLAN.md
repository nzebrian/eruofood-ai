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
