# EruoFood AI — GA Execution Guide (what to run OUTSIDE the sandbox)

This is the operator runbook for validating the five remaining GA blockers that
**cannot** be truthfully validated inside the Claude sandbox (no container
registry, no Flutter toolchain, no scaled staging, no cloud network fabric, no
external security team). Everything below is **READY TO EXECUTE** — none of it is
marked passed until *you* run it and it goes green.

> **READY TO EXECUTE ≠ EXECUTED AND PASSED.** Creating a workflow does not
> validate anything. A blocker flips to PASSED only when its workflow runs green
> (or, for the pentest, the assessor delivers a clean report).

## The five remaining blockers → the workflow that validates each

| # | Blocker | Validated by | Where it runs |
|---|---|---|---|
| 1 | Performance certification | `.github/workflows/performance-certification.yml` | GitHub → staging |
| 2 | Full Docker clean-boot | `.github/workflows/ga-docker-certification.yml` | GitHub-hosted runner |
| 3 | Flutter analyze/test/build | `.github/workflows/ga-flutter-certification.yml` | GitHub (Ubuntu + macOS) |
| 4 | Infrastructure egress enforcement | `infra/k8s/networkpolicy-webhook-egress.yaml` + provider config | your cluster/cloud |
| 5 | Independent external penetration test | `docs/PENETRATION_TEST_HANDOFF.md` | third-party assessor |

## OPERATOR CHECKLIST (do these in order)

### 1) FIRST — run the free CI gates (no infra needed)
Run **GA Docker Certification** and **GA Flutter Certification** from the GitHub
Actions tab (both are `workflow_dispatch`, and also run on push):
- `Actions → GA Docker Certification → Run workflow`
- `Actions → GA Flutter Certification → Run workflow`

These need only GitHub-hosted runners. When green, blockers **#2 and #3 are
EXECUTED AND PASSED**. Verify:
- **Docker:** the job reaches "Public API smoke tests / auth smoke OK" and tears
  down cleanly; on failure, download the `ga-docker-logs` artifact.
- **Flutter:** `flutter analyze`/`test`/`build apk` all green; the
  `eruofood-release-apk` artifact is produced (and the macOS `ios` job builds).

### 2) SECOND — configure the `staging` GitHub Environment
In `Settings → Environments → staging`, add the secrets/vars listed in
`docs/STAGING_DEPLOYMENT.md` (registry, deploy backend, app runtime, and
`STAGING_BASE_URL`/`STAGING_API_KEY`). **Do not commit secrets.**

### 3) THIRD — deploy staging
`Actions → Staging Deploy → Run workflow` (choose the ref). It builds+pushes
images and deploys via Kubernetes (`KUBE_CONFIG`) or Compose/SSH
(`STAGING_SSH_*`), runs migrations, and smoke-tests `/api/v1/health`,
`/api/v1/ready`, `/api/public/v1/status`. Staging is up when the smoke step is
green.

### 4) FOURTH — run performance certification against staging
`Actions → Performance Certification (k6 · staging) → Run workflow`. It runs the
baseline/load/stress/spike matrix (add soak via the input) across the Public API
and critical flows, and **fails on threshold breach** (`p95<400ms`, `p99<800ms`,
error `<1%`). Read the numbers in the job **Summary** and the
`perf-*` artifacts. When green, blocker **#1 is EXECUTED AND PASSED** — transcribe
the numbers into `docs/PERFORMANCE_REPORT.md`.

### 5) FIFTH — apply infrastructure egress enforcement
Apply `infra/k8s/networkpolicy-webhook-egress.yaml` to the cluster, then set the
**provider-specific** controls (AWS NAT/NACL + IMDSv2, or GCP/Azure equivalents)
per `docs/INFRA_EGRESS_POLICY.md` §2.4–2.5 and `infra/k8s/README.md`. Run the SSRF
infra acceptance test (from a webhook-worker pod, confirm `169.254.169.254`,
loopback, RFC1918 are all unreachable and public `:443` works). When it passes,
blocker **#4 is EXECUTED AND PASSED**. *This is the one step that is
provider-specific and cannot be fully expressed in portable manifests.*

### 6) SIXTH — arrange the independent penetration test
Send `docs/PENETRATION_TEST_HANDOFF.md` (+ `docs/PENETRATION_TEST_PLAN.md`, the
OpenAPI spec, and staging test accounts) to an independent security firm. Give
them the staging URL and rules of engagement. Blocker **#5 is validated only when
the assessor returns a report with no open Critical/High** (or High with signed
acceptance). **Do not self-attest this.**

### 7) FINALLY — consolidated GA certification
`Actions → GA Release Certification → Run workflow`, ticking the
`staging_smoke_passed`, `performance_passed`, and `pentest_cleared` inputs only
when each is truly green. The `certified` job fails unless staging smoke and
performance are confirmed; it warns (does not hard-fail) if the pentest is not yet
cleared, printing **"TECHNICALLY READY, PENDING INDEPENDENT SECURITY
ASSESSMENT."** Cut the production tag (`v*.*.*`) only after this is green — the
tag triggers `release.yml`, the last hard gate.

## What constitutes final production GO (evidence)
See `docs/PRODUCTION_RELEASE_CHECKLIST.md`. In short: every automated gate green,
staging performance numbers recorded, infra egress acceptance test passed, and an
independent pentest report with **no open Critical/High**.

## Honest status ledger

| Item | Status today (in this repo) |
|---|---|
| Backend runtime / PHPStan L8 / PostgreSQL / Redis / React / OpenAPI / backup-restore / Redis-resilience | **EXECUTED AND PASSED** (Milestones 18–21) |
| Docker clean-boot | **READY TO EXECUTE** (`ga-docker-certification.yml`) — not run here |
| Flutter | **READY TO EXECUTE** (`ga-flutter-certification.yml`) — toolchain absent here |
| Staging deploy | **READY TO EXECUTE** (`staging-deploy.yml`) — needs your secrets/target |
| Performance certification | **READY TO EXECUTE** (`performance-certification.yml`) — needs staging |
| Infra egress enforcement | **READY TO EXECUTE** (manifests + provider steps) — needs your cloud |
| External penetration test | **NOT VALIDATED — EXTERNAL REQUIREMENT** (handoff package ready) |
