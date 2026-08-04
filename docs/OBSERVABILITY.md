# EruoFood AI — Observability

Milestone 21. Production-readiness of logs, correlation, metrics, health/readiness,
error reporting, and per-subsystem monitoring, plus the deployment-ready alert
rules.

## 1. Structured logging

- `LOG_CHANNEL=stack`, `LOG_STDERR=true` in production: containers emit logs to
  stdout/stderr for the platform collector (CloudWatch / Cloud Logging / Loki).
- `LOG_LEVEL=warning` in production (never `debug`); `APP_DEBUG=false` so no
  stack traces leak to responses (asserted by the readiness posture).
- Use JSON-formatted output in production so logs are queryable by field. (Add a
  JSON formatter to the `stack` channel in `config/logging.php` at deploy time.)

## 2. Correlation IDs — EXECUTED — PASSED

- The Public API sets a per-request **`X-Request-Id`** (UUID) via
  `ApiRequestContext` middleware and returns it on every response, so a client
  can quote it and operators can trace a single request across logs.
- Payments carry a domain **`correlation_id`** through the ledger for financial
  traceability.
- **Recommendation:** honour an inbound `X-Request-Id`/`traceparent` when present
  (propagate rather than always regenerate) and push it into the log context so
  every log line for a request shares the id; emit W3C `traceparent` for OTLP
  distributed tracing (`OTEL_EXPORTER_OTLP_ENDPOINT` is templated in the prod env).

## 3. Metrics

Expose a Prometheus `/metrics` endpoint (app exporter) plus the standard
exporters: `postgres_exporter`, `redis_exporter`, `node_exporter`, and
`blackbox_exporter` (for the health/readiness probes). Key series:
- `http_requests_total{status}`, `http_request_duration_seconds_bucket` (RED metrics).
- `auth_failures_total` (security signal).
- `eruofood_queue_depth`, `eruofood_active_workers`, `eruofood_scheduler_last_run_timestamp`.
- `redis_*`, `pg_*` from the exporters.

## 4. Health & readiness — EXECUTED — PASSED

| Endpoint | Purpose | Probe use |
|---|---|---|
| `GET /api/v1/health` | Liveness (process up) | Kubernetes `livenessProbe` |
| `GET /api/v1/ready` | Readiness (DB + Redis reachable) → 200/503 | Kubernetes `readinessProbe` / LB health |

Both are feature-tested (`HealthEndpointTest`, `ReadinessEndpointTest`).

## 5. Error reporting

- `SENTRY_DSN` templated in `infra/env/production.env.example`; wire the Sentry
  (or equivalent) handler so unhandled exceptions are captured with the
  `X-Request-Id` tag for correlation.
- Alerting on error-rate is defined below (does not depend on Sentry).

## 6. Per-subsystem monitoring & alerts

Deployment-ready Prometheus rules: **`infra/monitoring/alert-rules.yaml`**. They
cover exactly the signals the milestone requires:

| Signal | Alert | Severity |
|---|---|---|
| API failure rate | `ApiHighErrorRate` (5xx > 1%) | critical |
| High latency | `ApiHighLatencyP95` / `ApiHighLatencyP99` | warning |
| PostgreSQL failure | `PostgresDown` (+ connections, replica lag) | critical/warning |
| Redis failure | `RedisDown` (+ memory, evictions) | critical/warning |
| Queue backlog | `QueueBacklogGrowing` (> 1000, 10m) | warning |
| Worker failure | `WorkerDown` / `SchedulerHeartbeatMissing` | critical |
| Disk / storage pressure | `DiskPressure` / `ObjectStorageErrors` | warning |
| Authentication anomalies | `AuthFailureSpike` | warning |

## 7. Validation status

| Item | Verdict |
|---|---|
| Structured logging to stdout/stderr configured | STATIC VALIDATION ONLY (config templated; collector is deployment-time) |
| Correlation IDs (`X-Request-Id`) | **EXECUTED — PASSED** (middleware + response header, tested) |
| Health / readiness endpoints | **EXECUTED — PASSED** (feature-tested) |
| Metrics endpoints + exporters | NOT VALIDATED (deployment-time; series names specified) |
| Alert rules | STATIC VALIDATION ONLY (authored, deployment-ready — `infra/monitoring/alert-rules.yaml`) |
| Distributed tracing / Sentry | NOT VALIDATED (env templated; wire at deploy) |
