# Analytics Module (`EruoFood\Analytics`)

The **Analytics, Business Intelligence & Reporting** bounded context — the
platform's read-side: it collects what happens across every module, rolls it up
into fast metrics, and serves dashboards, KPIs, reports and exports.

**No module writes into analytics.** Analytics *collects* data by subscribing to
published domain events on the shared event bus, keyed by the event's stable
name (never a typed import). Business modules simply publish events; this
context reacts, appends a raw fact, and projects it into pre-aggregated metric
buckets.

## What it owns

- **Analytics engine** — an **Event Collection Service** (the single ingress),
  an **event-processing pipeline** (`MetricProjector`) that writes daily metric
  buckets (total + per-dimension), a **KPI engine** (values with period-over-
  period deltas), a **Dashboard Service**, a **Report Generator**, an **Export
  Service**, and **Scheduled Reports**.
- **Business analytics** — revenue, sales trends, orders, customer growth,
  vendor/product performance, recipe popularity, AI usage and nutrition insights.
- **Operational analytics** — notifications throughput, failed payments, AI
  token consumption, and (extensible) API/queue/cache signals.
- **Financial reporting** — revenue, refunds, settlements, a financial summary,
  and tax-ready totals.
- **AI analytics** — token consumption and provider/model/feature breakdowns.
- **Dashboards** — executive, operations, finance, admin (company-wide, RBAC),
  and scoped vendor/restaurant dashboards.
- **Exports** — CSV (native), plus architecture-ready XLSX (a minimal valid
  workbook via ZipArchive) and PDF (a minimal valid document), and scheduled
  email reports.

## Folder structure

```
modules/Analytics/src/
├── Domain/                  # Pure PHP — no framework
│   ├── Enum/                # MetricOp, AnalyticsCategory, Granularity, DashboardType,
│   │                        #   ExportFormat, ReportStatus, ReportCadence
│   ├── ValueObject/         # DateRange, DataPoint, Dimension
│   ├── Metric/              # AnalyticsEvent, Kpi + AnalyticsEvent/Metric repository ports
│   ├── Report/              # Report, ScheduledReport + repos
│   └── Exception/           # not-found / invalid-state / not-authorized
├── Application/             # Use cases + ports
│   ├── Port/                # ReportExporter, ReportDelivery
│   ├── DTO/                 # ExportResult, ChartSeries, DashboardView
│   └── Service/             # EventCollection, MetricProjector, EventTranslator,
│                            #   KpiEngine, Dashboard, ReportGenerator, Export,
│                            #   ScheduledReport, Presenter
├── Infrastructure/          # Adapters
│   ├── Persistence/         # 4 Eloquent models + repositories, 4 migrations
│   ├── Export/              # NativeReportExporter (CSV/XLSX/PDF), LoggingReportDelivery
│   ├── Event/               # DomainEventSubscriber (bus → translator)
│   └── Provider/            # AnalyticsServiceProvider (composition root)
└── Interface/               # HTTP (dashboards, KPIs, reports, scheduled reports)
```

## How collection works (event-driven, no direct writes)

The shared `EventBus` dispatches each domain event by its stable `eventName()`
string. On boot, `DomainEventSubscriber` registers a listener for every event
name in `config/analytics.php → event_map`. When a module publishes a mapped
event, the `EventTranslator` reads the numeric value and dimensions from the
event's **public properties** (`get_object_vars`) — never a typed import — and
calls the Event Collection Service, which appends a raw `analytics_events` row
and increments the relevant `analytics_metrics` daily buckets. Adding a new
tracked metric is a one-line config entry.

## Metrics model

Metrics are stored **pre-aggregated per day**: one total bucket (no dimension)
plus one bucket per dimension value. This keeps dashboards O(days) regardless of
raw event volume. Reads sum the buckets over a `DateRange` and roll daily
buckets up to weekly/monthly on demand. KPIs compare a range to the equal-length
window immediately before it for trend arrows.

## Reports & exports

The Report Generator builds tabular reports (revenue, sales_trend,
customer_growth, financial, ai_usage, refunds, settlements, vendor_performance,
product_performance) from the metric store and persists them. The Export Service
serialises a report to **CSV** (native), **XLSX** (a minimal valid Office Open
XML workbook via the built-in ZipArchive) or **PDF** (a minimal valid document),
all with no third-party dependency. Scheduled reports generate + export + email
on a cadence and advance their own schedule.

## Authorisation

Company-wide dashboards, KPIs, reports and scheduled reports are `role:admin`.
Vendor/restaurant owners may read their own scoped dashboard.

## Persistence

Four `analytics_*` tables (events, metrics, reports, scheduled_reports). Other
contexts are referenced by ID/name only (soft refs).

## Error → HTTP mapping

`ANALYTICS_RESOURCE_NOT_FOUND` → 404, `ANALYTICS_NOT_AUTHORIZED` → 403,
`ANALYTICS_INVALID_STATE` → 422 (bad range).

## Testing

- **Unit** — date-range maths, KPI deltas & granularity bucketing, and the
  CSV/PDF exporters.
- **Feature** — a published domain event is collected into metrics and surfaces
  on the executive dashboard (proving the decoupled collection), non-admins are
  blocked, a financial report generates and exports as CSV, and a scheduled
  report is created & listed. All offline.

See [docs/api/analytics-endpoints.md](../../../../docs/api/analytics-endpoints.md)
and [ADR-0010](../../../../docs/adr/0010-analytics-platform.md).
