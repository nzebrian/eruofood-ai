# EruoFood AI — GA Release Checklist

The gate list for cutting a production GA release. Every **MANDATORY** item must
be green (or hold a signed waiver where noted). Automated items map to
`.github/workflows/release.yml`; manual items require a named owner's sign-off.

## A. Automated quality gates (release.yml — all MANDATORY)

- [ ] Pint coding standards — green
- [ ] **PHPStan Level 8 = 0 errors** on production code (residual per
      `docs/PHPSTAN_LEVEL8_REPORT.md` cleared) — green
- [ ] Full Pest suite on PostgreSQL 16 — 0 failures
- [ ] Fresh migrate + rollback + re-migrate on an empty PostgreSQL — green
- [ ] Redis primitives (`scripts/redis_validation.php`) — 9/9
- [ ] Web: typecheck + vitest + production build — green
- [ ] Flutter: `analyze` + `test` — green (mobile releases)
- [ ] OpenAPI contract lint (redocly) — 0 errors
- [ ] Security: Gitleaks + `composer audit` + `npm audit` — no High/Critical
- [ ] Docker images build (`api`, `nginx`, `web`) — green
- [ ] Docker clean-boot (`ci-docker.yml`): build → boot → migrate → health/ready — green

## B. Performance certification (MANDATORY)

- [ ] k6 suite (`load/`) run on staging; p50/p95/p99, RPS, error rate, CPU,
      memory, PostgreSQL & Redis latency, queue throughput recorded in
      `docs/PERFORMANCE_REPORT.md`
- [ ] Thresholds met: `p95 < 400ms`, `p99 < 800ms`, error rate `< 1%`
- Status today: **NOT VALIDATED** (needs staging infra)

## C. Security (MANDATORY)

- [ ] Application controls green (auth, OAuth2, BOLA, SSRF app-layer, secret
      hashing) — see `docs/SECURITY_AUDIT.md`
- [ ] Infrastructure egress policy applied per `docs/INFRA_EGRESS_POLICY.md`;
      SSRF infra acceptance test passed from a worker pod
- [ ] External penetration test performed; **no open Critical**, **no open High**
      without signed acceptance (`docs/PENETRATION_TEST_PLAN.md`)
- Status today: pentest **NOT PERFORMED**; infra egress **NOT VALIDATED**

## D. Data & recovery (MANDATORY)

- [ ] PITR + daily/weekly backups configured (`docs/BACKUP_RESTORE.md`)
- [ ] Restore drill passed within the last month (RTO/RPO met)
- [ ] Rollback plan rehearsed (`docs/ROLLBACK_PLAN.md`)
- [ ] DR runbook current; last region game-day recorded (`docs/DISASTER_RECOVERY.md`)

## E. Operational readiness (MANDATORY)

- [ ] Production/staging env populated from `infra/env/*.example` via secret manager
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, TLS to DB/Redis
- [ ] Health `/api/v1/health` + readiness `/api/v1/ready` wired to probes
- [ ] Alerting thresholds live (`docs/INCIDENT_RESPONSE.md` §4)
- [ ] On-call rota + Incident Commander assigned
- [ ] Secret rotation procedure verified (`docs/INCIDENT_RESPONSE.md` §5)

## F. Release identity

- [ ] Semver tag cut on `main`; `release.yml` green end-to-end
- [ ] Image digests pinned; promoted unchanged staging → production
- [ ] `APP_VERSION` set to the tag
- [ ] Migration plan is expand-only (`docs/PRODUCTION_DEPLOYMENT.md` §4)

## G. Sign-offs

| Area | Owner | Signed |
|---|---|---|
| Engineering (gates A) | | |
| Performance (B) | | |
| Security + pentest (C) | | |
| SRE / DR (D, E) | | |
| Product / GA go decision | | |

**A production release proceeds only when every MANDATORY item is green or holds a
recorded, signed waiver.** A failed mandatory gate blocks the release
(`release.yml` `release-gate` job will not pass).
