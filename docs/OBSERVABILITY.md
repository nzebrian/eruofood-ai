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

## 8. Financial and security alerting (M28)

Sections 1–7 watch whether the platform is **up**. They were written in M21 and,
like every other operations document in this repository, predate M27 — so when
settlement shipped, the entire financial subsystem arrived unmonitored. Nothing
watched for an unknown transfer outcome, a ledger that stopped balancing, or a
payout attempted twice.

Two groups in `infra/monitoring/alert-rules.yaml` close that:

### `eruofood-financial`

| Signal | Alert | Severity |
|---|---|---|
| UNKNOWN transfer outcome | `SettlementUnknownOutcome` (`for: 0m`) | critical |
| Unknowns not being resolved | `SettlementUnknownBacklog` (2h) | critical |
| Duplicate payout attempted | `DuplicatePayoutAttempted` | critical |
| Ledger does not net to zero | `LedgerImbalance` | critical |
| Payable ≠ derived payable | `PayableDrift` | critical |
| Reconciliation backlog | `ReconciliationBacklog` | warning |
| Settlement failure rate | `SettlementFailureRate` | warning |
| Provider unreachable | `PaymentProviderUnreachable` | critical |
| Abnormal payout volume | `AbnormalPayoutVolume` | critical |
| Financial job on a timer | `FinancialScheduleUnexpectedlyEnabled` | critical |

### `eruofood-security`

| Signal | Alert | Severity |
|---|---|---|
| Denied finance permission | `PrivilegedFinancialAccessDenied` | warning |
| Money moved out of hours | `FinancialActionOutsideBusinessHours` | warning |
| Break-glass access used | `BreakGlassAccessUsed` | critical |
| Adjustment or reversal performed | `SuperAdminFinancialOverride` | warning |
| Unsafe configuration detected | `ConfigurationChangeUnverified` | critical |

### Actionable, not decorative

Every rule in both groups carries an `owner` label and a `response` annotation
saying what the responder should actually do — including, where it is the right
first move, *disable `settlement.execute` before investigating*.
`AlertRuleCoverageTest` fails the build if a rule loses its owner, its response,
or its severity, if any required signal disappears, or if one of the four
un-tunable alerts is softened below critical.

`SettlementUnknownOutcome` fires at `for: 0m`. Waiting even five minutes to
mention that a transfer's outcome is unknown is five minutes in which somebody
could retry it.

### Required metrics

These series must be emitted by the app exporter for the rules above to fire.
Until settlement is enabled they are all flat at zero, which is the point — the
alerts are in place *before* the first live payout, not after the first incident:

`eruofood_settlement_runs_total{state}`, `eruofood_settlement_runs_unknown`,
`eruofood_payout_duplicate_rejected_total`, `eruofood_ledger_net_minor`,
`eruofood_payable_ledger_minor`, `eruofood_payable_derived_minor`,
`eruofood_reconciliation_cases_open`, `eruofood_gateway_transport_errors_total`,
`eruofood_payout_amount_minor_total`, `eruofood_scheduled_financial_tasks_enabled`,
`eruofood_authz_denied_total{permission}`, `eruofood_financial_actions_total{action}`,
`eruofood_break_glass_total`, `eruofood_environment_verification_failures`.

**Status: the exporter does not emit these yet.** The rules are authored and
tested; wiring the metrics is deployment-time work and is a precondition for
enabling `settlement.execute`, not for this milestone.
