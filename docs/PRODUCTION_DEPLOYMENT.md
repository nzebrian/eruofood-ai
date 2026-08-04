# EruoFood AI — Production Deployment

Deployment strategy, migration handling, and zero/low-downtime rollout for the
modular monolith (Laravel API + queue workers + scheduler), nginx, PostgreSQL,
Redis, and the React web app.

## 1. Architecture at runtime

| Component | Image / artifact | Scale | Notes |
|---|---|---|---|
| `api` (php-fpm) | `eruofood/api` | N replicas | Stateless; behind nginx |
| `nginx` | `eruofood/nginx` | N replicas | TLS termination or behind an LB/ingress |
| `worker` | `eruofood/api` (queue:work) | M replicas | Redis queues; graceful `SIGTERM` |
| `scheduler` | `eruofood/api` (schedule:run) | **1** | Singleton; leader-elected |
| `web` | `eruofood/web` (static) | CDN/replicas | Built React bundle |
| PostgreSQL 16 | managed | primary + replica | TLS, PITR enabled |
| Redis 7 | managed | primary + replica | TLS + auth |
| Object storage | S3-compatible | — | Media, exports |

Environment comes from `infra/env/production.env.example` populated by the secret
manager. Templates for Kubernetes live under `infra/k8s/` and IaC under
`infra/terraform/` (to be filled with the chosen provider).

## 2. Release identity

- **Tagging:** semantic version tags `vMAJOR.MINOR.PATCH` on `main` cut releases.
  Pre-releases use `vX.Y.Z-rc.N`. The tag is the single source of truth for
  `APP_VERSION` and the container image tag.
- Images are built once per tag and promoted **unchanged** staging → production
  (no rebuild between environments). Digests are pinned in the deploy manifest.

## 3. Pre-deployment gates (must all pass)

A production deploy is only permitted from a tag whose `release` workflow is green
(see `.github/workflows/release.yml`):

1. Pint (coding standards)
2. **PHPStan Level 8 = 0 errors** (hard gate — the documented residual in
   `docs/PHPSTAN_LEVEL8_REPORT.md` must be cleared before a production tag)
3. Full Pest suite on PostgreSQL 16 (0 failures)
4. Fresh-migration + rollback + re-migrate on an empty PostgreSQL
5. Redis validation script
6. React: typecheck, vitest, production build
7. Flutter: `analyze` + `test` (for mobile releases)
8. OpenAPI contract lint (redocly)
9. Security scan (Gitleaks, `composer audit`, `npm audit`)
10. Docker images build for `api`, `nginx`, `web`

## 4. Migration deployment strategy (expand/contract)

Migrations run **before** the new code serves traffic, and must be
backward-compatible with the currently-running version so old and new pods
coexist during rollout:

1. **Expand** (this release): add columns/tables/indexes as **nullable / additive
   only**. Never drop or rename in the same release that changes code behaviour.
2. Deploy new code (rolling — see §5). Both versions read/write the expanded
   schema safely.
3. **Contract** (a *later* release, after the old version is fully gone): drop the
   deprecated columns/tables.

Run migrations as a **pre-deploy job** (Kubernetes `Job` / one-off task), not from
an app pod, so exactly one runner applies them:

```bash
php artisan migrate --force --no-interaction
```

Large/backfill migrations run as separate, resumable, batched jobs — never inline
in a request. Index creation on large tables uses `CREATE INDEX CONCURRENTLY`
(a dedicated migration outside a transaction).

## 5. Zero / low-downtime rollout

- **Rolling update** with `maxUnavailable: 0`, `maxSurge: 25%`: new pods must pass
  readiness before old pods are drained.
- **Health/readiness probes** (already in `docker-compose.yml` healthchecks;
  mirror in k8s):
  - Liveness: `GET /api/v1/health` (process up)
  - Readiness: `GET /api/v1/ready` (DB + Redis reachable, migrations at head)
- **Graceful shutdown:** workers trap `SIGTERM`, finish the in-flight job, then
  exit (`queue:work --stop-when-empty` semantics via a preStop hook + termination
  grace period ≥ the longest job).
- **Cache/opcache:** run `php artisan config:cache route:cache event:cache` at
  image build; clear+warm on boot. Opcache is enabled (`infra/docker/php/opcache.ini`).
- **Scheduler singleton:** only one scheduler replica; use leader election or a
  single-replica Deployment.

## 6. Deploy procedure (runbook)

```bash
# 1. Cut the tag (triggers release.yml gates + image build/push)
git tag -a v1.0.0 -m "GA" && git push origin v1.0.0

# 2. After release.yml is green, promote the pinned image digests:
#    a) run the migration Job (expand-only)
kubectl apply -f infra/k8s/jobs/migrate.yaml
#    b) roll the api/worker/web deployments to the new digest
kubectl set image deploy/api api=eruofood/api@<digest> ...
#    c) watch readiness + error rate + p95 for the bake period (≥15 min)

# 3. Smoke test
curl -fsS https://api.eruofood.ai/api/v1/health
curl -fsS https://api.eruofood.ai/api/public/v1/status
```

## 7. Post-deploy verification

- Health + readiness green on all replicas.
- Error rate < 1%, p95 within `PERFORMANCE_REPORT.md` thresholds for the bake
  period.
- Queue depth draining; scheduler heartbeat present.
- No new Sentry/error spikes.

If any check fails, execute `docs/ROLLBACK_PLAN.md`.

## 8. Configuration & secrets

- All secrets via the platform secret manager; nothing in the image or git.
- Secret rotation procedure: `docs/INCIDENT_RESPONSE.md` §Secret rotation.
- `APP_DEBUG=false`, `APP_ENV=production` are enforced and asserted by the
  readiness probe.
