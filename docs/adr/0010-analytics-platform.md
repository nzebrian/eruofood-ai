# ADR-0010: Analytics — an event-collecting read-model with pre-aggregated metrics

- **Status:** Accepted
- **Date:** 2026-07-27
- **Deciders:** Engineering, Product, Finance

## Context

Milestone 10 adds Analytics, Business Intelligence & Reporting: business,
operational, financial and AI analytics; executive/operations/finance/vendor
dashboards with KPIs and charts; report generation and CSV/XLSX/PDF exports; and
scheduled email reports. The hard requirements: an **independent bounded
context**, analytics must **collect data through domain events**, and **no
module may write directly into analytics**.

## Decision

- **A standalone `EruoFood\Analytics` context that only *collects*.** It exposes
  no write API to other contexts. It subscribes to the shared event bus and
  turns published domain events into analytics — the same decoupled pattern the
  Notifications context uses, applied as a data sink.
- **Collection by event *name*, via reflection.** A config `event_map`
  associates each event name with a metric, category, operation (count|sum),
  optional value key and dimension keys. `DomainEventSubscriber` registers a
  listener per name; the `EventTranslator` reads the numeric value and dimensions
  from the event's **public properties** (`get_object_vars`) — never importing
  another context's event class. Adding a tracked metric is a one-line config
  edit, and no business module knows analytics exists.
- **Two-tier storage: raw facts + pre-aggregated metrics.** Every collected
  event is appended to `analytics_events` (an audit trail), and the projection
  pipeline increments **daily metric buckets** in `analytics_metrics` — one total
  bucket plus one per dimension value. Dashboards and reports read the buckets,
  so query cost is O(days) not O(events); rolling daily buckets up to
  weekly/monthly happens on read. This is CQRS-lite: a write-optimised event log
  and a read-optimised metric store, no full event sourcing.
- **KPIs with period-over-period deltas.** Each KPI compares its range to the
  equal-length window immediately before it, so the UI shows trend direction
  without extra client logic.
- **Dependency-free exports behind a port.** `ReportExporter` produces CSV
  natively, a minimal but valid **XLSX** (Office Open XML zipped with the
  built-in `ZipArchive`) and a minimal but valid **PDF** (hand-built with a
  correct xref table) — no third-party library, so exports and scheduled reports
  run offline. Richer styling can replace the adapter without touching callers.
  Scheduled delivery is a `ReportDelivery` port (log default; a mailer or the
  Notifications context can be plugged in).

## Consequences

- **Positive:** analytics never couples to another context's internals; new
  metrics are config; dashboards stay fast as event volume grows; exports and
  scheduled reports work with zero external dependencies; the collection pipeline
  is fully testable offline (publish an event, assert a dashboard value).
- **Negative / trade-offs:** metric increments are read-modify-write and assume a
  single synchronous writer — correct for in-process collection but a
  high-throughput deployment should switch to an atomic upsert or a queued/batch
  projector (the raw `analytics_events` log makes re-projection/backfill
  possible). The XLSX/PDF writers are minimal (data, not styling). Some
  operational metrics (API/queue/cache/DB) require emitting the corresponding
  domain/infra events, which are added as those signals are published. Dashboards
  read pre-defined metric keys; genuinely ad-hoc slicing would need a query layer
  over the raw events.
- **Follow-ups:** a queued/batch projector and atomic upserts for scale; a
  backfill command that re-projects `analytics_events`; emitting
  API/queue/cache/DB operational events; a real mailer for scheduled reports; and
  optionally a columnar/warehouse sink for ad-hoc BI.

## Alternatives considered

- **Let modules write metrics directly** — rejected outright: violates the
  "events only / no direct writes" requirement and couples every module to the
  analytics schema.
- **Compute dashboards by scanning source tables across contexts** — rejected:
  cross-context joins break module boundaries and get slow; the event-fed metric
  store keeps analytics self-contained and fast.
- **Full event sourcing / a warehouse from day one** — heavier than a launch
  needs; the raw event log + daily metric buckets give most of the benefit and
  leave the door open to a warehouse later.
