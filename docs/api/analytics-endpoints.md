# Analytics, Business Intelligence & Reporting — API Endpoints

Base URL: `/api/v1`. All paths are under **`/analytics`** and require a bearer
token. Company-wide dashboards, KPIs, reports and scheduled reports are
`admin`-only; a vendor/restaurant owner may read their own scoped dashboard.
Analytics data is **collected from domain events**, never posted by clients —
there is no write API. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Dashboards & KPIs

| Method & Path | Purpose |
|---|---|
| `GET /analytics/dashboards/{type}` | A company dashboard (`executive`\|`operations`\|`finance`\|`admin`, RBAC). KPIs + charts + breakdowns. Range via `days` or `from`+`to`. |
| `GET /analytics/dashboards/vendor` · `…/restaurant` | A scoped owner dashboard (`vendor_id`, defaults to the caller). |
| `GET /analytics/kpis` | The headline KPI row (RBAC). |

**Dashboards & their metrics**

| Dashboard | KPIs | Charts | Breakdowns |
|---|---|---|---|
| executive / admin | revenue, orders, new customers, AI tokens | revenue, orders | revenue by provider |
| finance | revenue, refunds, settlements, failed payments | revenue, refunds | settlements by payee type |
| operations | notifications, AI tokens, failed payments | notifications, AI tokens | notifications by channel, AI tokens by provider |
| vendor / restaurant | my orders | — | orders by vendor |

## Reports & exports (RBAC)

| Method & Path | Purpose |
|---|---|
| `GET /analytics/reports/catalogue` | The available report keys. |
| `GET /analytics/reports` | Recently generated reports (paginated). |
| `POST /analytics/reports` | Generate a report (`key`; range via `days`/`from`+`to`). |
| `GET /analytics/reports/{id}` | A generated report (columns + rows). |
| `GET /analytics/reports/{id}/export?format=` | Download the report as `csv`\|`xlsx`\|`pdf`. |

Report keys: `revenue`, `sales_trend`, `customer_growth`, `financial`,
`ai_usage`, `refunds`, `settlements`, `vendor_performance`, `product_performance`.

## Scheduled reports (RBAC)

| Method & Path | Purpose |
|---|---|
| `GET /analytics/scheduled-reports` · `POST …` | List / schedule a recurring emailed report (`name`, `report_key`, `cadence`, `format`, `recipients[]`). |
| `POST /analytics/scheduled-reports/run-due` | Run all due scheduled reports now (worker trigger). |
| `POST /analytics/scheduled-reports/{id}/deactivate` | Stop a scheduled report. |

## How data is collected (no HTTP)

Business modules publish domain events on the shared bus; the Analytics context
subscribes by the event's stable name and collects it into metrics, using
`config/analytics.php → event_map`. Mapped events include:
`identity.user_registered` (customers), `commerce.order_placed` /
`marketplace.order_placed` (orders), `commerce.product_published` /
`catalog.recipe_published` (products), `payments.payment_succeeded` (revenue, by
provider), `payments.payment_failed` (failed payments),
`payments.refund_completed` (refunds), `payments.settlement_completed`
(settlements, by payee type), `ai.request_completed` (AI tokens, by
provider/model/feature), `nutrition.health_profile_updated`, and
`notifications.dispatched` (by channel). Track a new metric by adding one map
entry.

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `ANALYTICS_RESOURCE_NOT_FOUND` | 404 | Report / scheduled report / unknown dashboard. |
| `ANALYTICS_NOT_AUTHORIZED` | 403 | Not permitted to view this dashboard/report. |
| `ANALYTICS_INVALID_STATE` | 422 | Invalid date range. |
