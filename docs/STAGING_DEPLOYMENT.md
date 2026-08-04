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
