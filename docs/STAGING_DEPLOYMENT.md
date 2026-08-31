# EruoFood AI — Staging Deployment

How to stand up a production-like staging environment and deploy to it via
`staging-deploy.yml`. Staging mirrors production (Laravel + nginx + PostgreSQL +
Redis + workers + scheduler + React + object storage + HTTPS) with **non-production
data and secrets**. No secret is hard-coded — all come from the GitHub `staging`
Environment.

## 1. Create the GitHub `staging` Environment
`Settings → Environments → New environment → staging`. Add protection rules
(required reviewers, wait timer) as desired. Add the following.

### Variables (non-secret)
| Name | Example | Purpose |
|---|---|---|
| `REGISTRY` | `ghcr.io` | image registry (default GHCR) |
| `IMAGE_PREFIX` | `nzebrian/eruofood-ai` | image path prefix |
| `STAGING_URL` | `https://staging.api.eruofood.ai` | smoke-test + environment URL |

### Secrets — deploy backend (pick ONE)
| Backend | Secrets |
|---|---|
| **Kubernetes** | `KUBE_CONFIG` (base64 kubeconfig), `STAGING_NAMESPACE` |
| **Compose/SSH** | `STAGING_SSH_HOST`, `STAGING_SSH_USER`, `STAGING_SSH_KEY` |
| Registry (if not GHCR) | `REGISTRY_USER`, `REGISTRY_TOKEN` |

### Secrets — application runtime
Delivered to the platform's own secret store (K8s External Secrets / host `.env`
from a vault), never echoed in logs. Derived from `infra/env/staging.env.example`:
`STAGING_APP_KEY`, `STAGING_DB_*`, `STAGING_REDIS_*`, `STAGING_JWT_SECRET`,
`STAGING_AWS_*`, `STAGING_MAIL_*`, `STAGING_OAUTH_*`.

### Secrets — performance testing
`STAGING_BASE_URL`, `STAGING_API_KEY` (a staging Public API key), optional
`STAGING_OAUTH_CLIENT_ID` / `STAGING_OAUTH_CLIENT_SECRET`.

## 2. Topology (production-equivalent)
Defined by `docker-compose.yml` + `docker-compose.staging.yml` (compose backend)
or the manifests under `infra/k8s/` (Kubernetes backend). TLS terminates at the
ingress/LB in front of nginx. `APP_ENV=staging`, `APP_DEBUG=false`, TLS to
DB/Redis, Redis AOF on. Health `GET /api/v1/health`, readiness `GET /api/v1/ready`.

## 3. Deploy
`Actions → Staging Deploy → Run workflow` (pick the ref). The workflow:
1. builds + pushes `api` and `web` images to the registry;
2. selects the deploy backend from the secrets present;
3. **Kubernetes:** applies `infra/k8s/` (incl. the egress NetworkPolicy), rolls the
   image tags, runs the expand-only migration Job, waits for rollout;
   **Compose/SSH:** pulls + `up -d --wait` with the staging override, runs migrations;
4. smoke-tests `/api/v1/health`, `/api/v1/ready`, `/api/public/v1/status`.

Staging is ready when the smoke step is green.

### 3.1 The deploy fails closed (M44)

That list describes what the workflow was always *meant* to do. Until M44 four
steps could report success without doing it, and this section records what
changed so a future reader does not reintroduce any of them.

| Was | Now |
| --- | --- |
| `kubectl set image` × 3 ended in `\|\| true` | unmasked — a failed roll fails the deploy |
| only `deploy/api` was waited on | every deployment that is rolled is waited on |
| `apply -f infra/k8s/jobs/migrate.yaml \|\| echo "add …"`, against a file that **did not exist** | the manifest exists, the apply is unmasked, and the Job is waited on |
| smoke test `exit 0` with a warning when `STAGING_URL` was unset | a missing `STAGING_URL` is an **error**: an unverifiable deploy is a failed deploy |
| `${{ inputs.ref }}` spliced into the script run on the staging host | the ref travels through `env:` and reaches the remote as `$1` |

The middle row is the one worth pausing on: **no Kubernetes staging deploy has
ever run a migration.** The apply failed because the manifest was absent, the
`echo` succeeded, and the step's exit status was the `echo`'s. Every one of
those deploys was green.

`STAGING_URL` is now **required**, not optional. A deploy that cannot be probed
stops rather than reporting the same green tick as one that was verified.

`.github/scripts/verify_deployment_safety.py` asserts all of the above and runs
inside the required `CI · Workflow Integrity` context;
`.github/scripts/m44_deployment_safety_control.sh` reinstates each historical
defect in a throwaway fixture and requires the validator to reject it by name.

## 4. Logging & monitoring
- Containers log to stdout/stderr → platform collector (`LOG_STDERR=true`).
- Load the Prometheus rules `infra/monitoring/alert-rules.yaml`; wire the
  exporters per `docs/OBSERVABILITY.md`.

## 5. Security
- Never commit real secrets. Rotate the staging API/OAuth keys after the pentest.
- Staging DB/Redis reachable only from the app security group.
- Apply the egress NetworkPolicy + provider controls (`docs/INFRA_EGRESS_POLICY.md`)
  before the pentest so SSRF defence-in-depth is testable.

## Status
The deploy workflow is **READY TO EXECUTE**. Deploying staging and its
end-to-end validation are **not** performed in the sandbox (no target/secrets).
