# EruoFood AI — CI Certification

The GitHub Actions workflows that certify the platform, what each proves, and the
mandatory-gate matrix. A production tag must not be cut unless the mandatory gates
are green.

## Workflows

| Workflow | File | Trigger | Proves |
|---|---|---|---|
| CI · API | `ci-api.yml` | push/PR | Pint, PHPStan L8, Pest+coverage, migrate, Redis (per-PR fast feedback) |
| CI · Web | `ci-web.yml` | push/PR | typecheck, vitest, build |
| CI · Mobile | `ci-mobile.yml` | push/PR | Flutter analyze + test |
| Contracts | `contracts.yml` | push/PR | OpenAPI lint + TS client gen |
| Security | `security.yml` | push/PR/schedule | Gitleaks, composer/npm audit |
| **GA Docker Certification** | `ga-docker-certification.yml` | dispatch/push/call | clean-env build→boot→migrate-from-zero→backend+PG+Redis integration→OpenAPI→smoke→teardown |
| **GA Flutter Certification** | `ga-flutter-certification.yml` | dispatch/push/call | real SDK: doctor, pub get, analyze, test, build apk (+ iOS build on macOS) |
| **Staging Deploy** | `staging-deploy.yml` | dispatch/call | build+push images, deploy staging (k8s or compose/SSH), migrate, smoke |
| **Performance Certification** | `performance-certification.yml` | dispatch/call | k6 baseline/load/stress/spike(/soak) vs staging with pass/fail thresholds |
| **GA Release Certification** | `ga-release-certification.yml` | dispatch | consolidated gate — blocks unless all mandatory gates green |
| Release · Production Gates | `release.yml` | tag `v*.*.*` | final hard gate on the tag before promotion |

## Mandatory-gate matrix (GA Release Certification)

| Gate | Job | Blocking? | Where |
|---|---|---|---|
| Backend runtime (Pest, PostgreSQL) | `backend` | Yes | CI |
| PHPStan Level 8 | `backend` | Yes | CI |
| Coding standards (Pint) | `backend` | Yes | CI |
| Composer validate | `backend` | Yes | CI |
| PostgreSQL migrate/rollback/re-migrate | `backend` | Yes | CI |
| Redis integration | `backend` | Yes | CI |
| Security (composer audit + Gitleaks) | `backend`, `security-scan` | Yes | CI |
| React typecheck/test/build | `web` | Yes | CI |
| OpenAPI lint | `openapi` | Yes | CI |
| Docker clean boot | `docker-clean-boot` (calls GA Docker Cert) | Yes | CI |
| Flutter analyze/test/build | `flutter` (calls GA Flutter Cert) | Yes | CI |
| Staging smoke tests | `certified` input `staging_smoke_passed` | Yes | staging |
| Performance thresholds | `certified` input `performance_passed` | Yes | staging |
| External pentest cleared | `certified` input `pentest_cleared` | Warn (see policy) | external |

The `certified` job **fails** unless staging smoke + performance are confirmed; it
**warns** (does not hard-fail) when the pentest is not yet cleared, printing
"TECHNICALLY READY, PENDING INDEPENDENT SECURITY ASSESSMENT" — because that single
item is external and time-boxed by policy, whereas the rest are code/infra gates.

## Honesty rule

These workflows being present means **READY TO EXECUTE**. A gate is **EXECUTED AND
PASSED** only after the workflow runs green on GitHub. This repo does not, and
cannot, mark Docker/Flutter/performance/egress/pentest as passed from inside the
sandbox.
