# EruoFood AI — Staging Certification

Milestone 21. Certifies the staging environment definition and what has been
executed against production-equivalent components in this session, versus what
must still run on a deployed staging cluster. Verdicts: **EXECUTED — PASSED /
STATIC VALIDATION ONLY / NOT VALIDATED**.

## 1. Staging topology (production-equivalent)

Defined by `docker-compose.yml` + `docker-compose.staging.yml` and
`infra/env/staging.env.example`:

| Component | Staging | Production |
|---|---|---|
| nginx | ✓ (TLS at ingress/LB) | ✓ |
| Laravel API (php-fpm) | ✓ ×2 replicas | ✓ ×N |
| Queue workers | ✓ ×2 | ✓ ×M |
| Scheduler | ✓ ×1 (singleton) | ✓ ×1 |
| PostgreSQL 16 | managed (or compose) | managed + replica + PITR |
| Redis 7 | AOF on; managed HA in cloud staging | managed HA |
| Object storage (S3/MinIO) | ✓ | ✓ |
| HTTPS | ingress/LB termination | ingress/LB termination |
| Secrets | staging secret store (no prod secrets) | prod secret store |
| Logging / monitoring | stdout/stderr + Prometheus rules | same |

`APP_ENV=staging`, `APP_DEBUG=false`, TLS to DB/Redis — mirrors production.

## 2. Executed against production-equivalent components (this session)

| Check | Engine/tool | Verdict |
|---|---|---|
| Full Pest suite | PostgreSQL 16 (+ SQLite) | **EXECUTED — PASSED 338/338** on both |
| PHPStan Level 8 | phpstan+larastan | **EXECUTED — PASSED (0 errors)** |
| Coding standards | Pint | **EXECUTED — PASSED** |
| Migrate from empty DB | PostgreSQL 16 | **EXECUTED — PASSED** (104 tables) |
| Backup / restore drill | pg_dump/pg_restore on PG16 | **EXECUTED — PASSED** (105 tables/406 idx round-trip) |
| Redis primitives | Redis 7 | **EXECUTED — PASSED** (9/9) |
| Redis outage → limiter fail-closed | unit | **EXECUTED — PASSED** |
| OAuth2 DB-backed security | PostgreSQL 16 | **EXECUTED — PASSED** (18/18) |
| Web typecheck / tests / build | node 22 | **EXECUTED — PASSED** (51/51, build clean) |
| Readiness/health probes | feature tests | **EXECUTED — PASSED** |

## 3. Must run on a deployed staging cluster (not possible in this sandbox)

| Check | Why | Artifact |
|---|---|---|
| k6 performance certification (baseline/load/stress/spike/soak) | Needs scaled, network-isolated staging | `load/` + `docs/PERFORMANCE_REPORT.md` |
| Full Docker clean-boot | Container registry is 403-blocked in-session | `.github/workflows/ci-docker.yml` |
| Flutter `analyze`/`test`/`build apk` | Flutter toolchain absent | `.github/workflows/ci-mobile.yml` |
| Infrastructure egress enforcement + SSRF acceptance | Provider-dependent network fabric | `docs/INFRA_EGRESS_POLICY.md` |
| External penetration test | Independent third party | `docs/PENETRATION_TEST_PLAN.md` |

## 4. Certification statement

The staging **definition** is production-equivalent and complete, and every check
that a single container can run against the production engines (PostgreSQL 16,
Redis 7, Node 22, PHP 8.4) is **EXECUTED — PASSED**. Staging is therefore
**certified ready to host** the remaining deployment-time validations (§3). It is
**not** yet certified end-to-end, because those five items require a deployed
staging cluster and an external party — each has a ready, executable artifact.
