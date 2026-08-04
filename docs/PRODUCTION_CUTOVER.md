# EruoFood AI — Production Cutover Procedure

The end-to-end runbook for the initial production go-live (and each subsequent
release). Complements `docs/PRODUCTION_DEPLOYMENT.md` (strategy) and
`docs/ROLLBACK_PLAN.md` (reversal). Every step is a checkbox the release captain
ticks live.

## 0. Roles & window
- **Release captain** (drives the cutover), **on-call SRE**, **security owner**.
- Announce the maintenance/deploy window; freeze unrelated deploys.

## 1. Pre-deployment checks (gate — all must be green)
- [ ] `release.yml` for the target tag is **fully green** (Pint, **PHPStan L8 = 0**,
      Pest on PostgreSQL, migrate/rollback, Redis, web build, Flutter, OpenAPI,
      security scan, image builds).
- [ ] `ci-docker.yml` clean-boot green (build → boot → migrate → health/ready).
- [ ] Performance certification recorded in `PERFORMANCE_REPORT.md` (thresholds met).
- [ ] External pentest: **no open Critical/High** (or signed acceptance) —
      `PENETRATION_TEST_PLAN.md` §6.
- [ ] Latest **backup restore drill passed** within the retention window
      (`BACKUP_RESTORE.md`); take a fresh pre-cutover backup now.
- [ ] Secrets present in the secret manager for all `__SET__` keys
      (`infra/env/production.env.example`); `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] Infrastructure egress policy applied (`INFRA_EGRESS_POLICY.md`); SSRF infra
      acceptance test passed.
- [ ] Rollback plan reviewed; previous image digest known and retained.

## 2. Deployment sequence
1. [ ] **Fresh backup** of production PostgreSQL (base + WAL position noted).
2. [ ] **Migrations (expand-only)** via a one-off Job — exactly one runner:
       `php artisan migrate --force --no-interaction`. Confirm `migrate:status`
       shows head. (Additive only; no drops/renames — `PRODUCTION_DEPLOYMENT.md` §4.)
3. [ ] Deploy new **image digests** to `api`, `worker`, `web`, `scheduler`,
       `nginx` with a **rolling update** (`maxUnavailable: 0`). New pods must pass
       `/api/v1/ready` before old pods drain.
4. [ ] Confirm **one** scheduler replica; workers reconnected to Redis; queues draining.
5. [ ] Warm caches (`config:cache route:cache event:cache` baked at build).

## 3. Smoke tests (must pass before opening traffic)
```bash
curl -fsS https://api.eruofood.ai/api/v1/health          # liveness
curl -fsS https://api.eruofood.ai/api/v1/ready           # DB + Redis ready
curl -fsS https://api.eruofood.ai/api/public/v1/status   # public API
# authenticated smoke (staging key style): list a resource, place+cancel a test order,
# obtain an OAuth2 token, confirm 429 under a burst, confirm a webhook delivers.
```
- [ ] All smoke checks green; `X-Request-Id` present on responses.

## 4. Health validation (bake period ≥ 15 min)
- [ ] Health + readiness green on **all** replicas.
- [ ] Error rate < 1%; p95/p99 within `PERFORMANCE_REPORT.md` thresholds.
- [ ] Queue depth draining; scheduler heartbeat present.
- [ ] No new Sentry spikes; alert rules quiet (`infra/monitoring/alert-rules.yaml`).

## 5. Rollback triggers (any → execute `ROLLBACK_PLAN.md`)
- Sustained 5xx > 1%, p95/p99 beyond thresholds, readiness failing on a material
  fraction of pods, a broken critical flow (auth/orders/payments), or any
  data-integrity anomaly.

## 6. Rollback procedure (summary — full detail in `ROLLBACK_PLAN.md`)
- [ ] `kubectl rollout undo` `api`/`worker`/`web` to the retained digest (code-only
      rollback; expand-only migrations keep the old version schema-compatible).
- [ ] If a destructive migration was involved (it should not be), PITR-restore to
      just before cutover (`BACKUP_RESTORE.md`).
- [ ] Re-run §3 smoke tests; open incident (`INCIDENT_RESPONSE.md`).

## 7. Post-deployment monitoring (first 24–48 h)
- [ ] Heightened watch on the alert dashboard; error budget tracked.
- [ ] Review logs for new warnings; confirm queue/scheduler steady-state.
- [ ] Confirm backup jobs ran post-cutover; schedule the next restore drill.
- [ ] Close the cutover record; capture follow-ups.

## 8. Go / No-Go call
The release captain declares **GO** only when §1 is fully green and §4 bake is
clean. Any unmet mandatory gate → **NO-GO**, postpone, remediate.
