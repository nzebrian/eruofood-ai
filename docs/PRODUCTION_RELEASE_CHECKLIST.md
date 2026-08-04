# EruoFood AI — Production Release Checklist (final GO evidence)

The evidence required for a production GO. Each row flips to ✅ only when the named
workflow/artifact is **green/received** — never because it exists.
Legend: ✅ EXECUTED AND PASSED · 🟡 READY TO EXECUTE (not yet run) · ⛔ external.

## A. Automated gates (must be ✅ before cutting a tag)
| Gate | Evidence | Status here |
|---|---|---|
| Backend runtime (Pest) on PostgreSQL 16 | `ga-release-certification` → `backend` | ✅ (338/338 executed in-repo M21) |
| PHPStan Level 8 = 0 | `backend` / `composer run analyse` | ✅ (0 errors, M21) |
| Coding standards (Pint) | `backend` | ✅ |
| Composer validate | `backend` | ✅ |
| PostgreSQL migrate/rollback/re-migrate | `backend` | ✅ |
| Redis integration | `backend` | ✅ (9/9) |
| Security (composer audit + Gitleaks) | `backend`, `security-scan` | 🟡 run in CI |
| React typecheck/test/build | `web` | ✅ (51/51, build clean) |
| OpenAPI lint | `openapi` | 🟡 redocly runs in CI (parses in-repo) |
| **Docker clean boot** | `ga-docker-certification` green | 🟡 run on GitHub |
| **Flutter analyze/test/build apk** | `ga-flutter-certification` green | 🟡 run on GitHub |

## B. Staging validations (must be ✅)
| Gate | Evidence | Status |
|---|---|---|
| Staging deployed + smoke green | `staging-deploy.yml` smoke step | 🟡 |
| **Performance thresholds met** | `performance-certification.yml` green + numbers in `PERFORMANCE_REPORT.md` | 🟡 |
| Backup/restore drill (recent) | `docs/BACKUP_RESTORE.md` | ✅ (executed M21) |
| Redis resilience (fail-closed) | `RateLimiterResilienceTest` | ✅ |

## C. Security & infrastructure (must be ✅)
| Gate | Evidence | Status |
|---|---|---|
| Application security controls | OAuth2 18/18, SSRF 25/25, BOLA | ✅ |
| **Infra egress enforcement** applied + SSRF infra acceptance test | `infra/k8s/networkpolicy-webhook-egress.yaml` + provider config | 🟡 provider-specific |
| **External penetration test** — no open Critical/High | assessor report (`PENETRATION_TEST_HANDOFF.md`) | ⛔ external |

## D. Operational readiness (must be ✅)
| Item | Evidence | Status |
|---|---|---|
| Prod/staging env from templates via secret store | `infra/env/*.example` | 🟡 deploy-time |
| `APP_DEBUG=false`, TLS to DB/Redis | env + readiness | ✅ enforced |
| Health `/api/v1/health` + readiness `/api/v1/ready` wired to probes | feature-tested | ✅ |
| Alerting live | `infra/monitoring/alert-rules.yaml` | 🟡 deploy-time |
| On-call + Incident Commander; secret rotation verified | `docs/INCIDENT_RESPONSE.md` | 🟡 org process |
| Rollback rehearsed; DR game-day recorded | `ROLLBACK_PLAN.md` / `DISASTER_RECOVERY.md` | 🟡 |

## E. Release execution
| Step | Evidence |
|---|---|
| `ga-release-certification` green (A + B confirmed) | workflow run |
| Semver tag `vX.Y.Z` cut; `release.yml` green | workflow run |
| Cutover per `PRODUCTION_CUTOVER.md`; bake period clean | cutover record |

## FINAL GO decision rule
Declare **GO** only when **A, B, C, D are ✅**. If **A + B + C-infra + D are ✅ and
the *only* remaining item is the external penetration test**, the status is:

> **TECHNICALLY READY FOR PRODUCTION — PENDING INDEPENDENT SECURITY ASSESSMENT.**

Do **not** convert any 🟡/⛔ to ✅ merely because the workflow or document exists.
