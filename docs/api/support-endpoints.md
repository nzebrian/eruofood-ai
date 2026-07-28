# Customer Support, Helpdesk & CRM — API Endpoints

Base URL: `/api/v1`. All paths are under **`/support`**. The knowledge base is
**public** to browse; raising and tracking tickets requires authentication; the
agent workspace, CRM and admin dashboards require a **support/admin role**
(enforced in the controllers). **No business module manages tickets** — every
support interaction flows through this context, and the CRM is built from
published domain events. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Knowledge base (public)

| Method & Path | Purpose |
|---|---|
| `GET /support/kb/articles` | Browse/search published articles (`q`, `category`). |
| `GET /support/kb/categories` | Distinct article categories. |
| `GET /support/kb/articles/{slug}` | Read a published article. |
| `POST /support/kb/articles/{slug}/vote` | Vote an article helpful/unhelpful. |

## Customer portal (auth)

| Method & Path | Purpose |
|---|---|
| `GET /support/tickets` | The caller's tickets (summaries). |
| `POST /support/tickets` | Open a ticket (`subject`, `category`, `body`, `priority`, `channel`, `attachments`). |
| `GET /support/tickets/{id}` | View a ticket — **public messages only** (internal notes hidden). |
| `POST /support/tickets/{id}/reply` | Reply (a reply to a resolved ticket reopens it). |
| `POST /support/tickets/{id}/csat` | Rate a resolved ticket (score 1–5 + comment). |

## Agent workspace (agent role)

| Method & Path | Purpose |
|---|---|
| `GET /support/agent/tickets` | The queue (filter `status`, `priority`, `assignee_id`, `unassigned`, `open`), urgency-ordered. |
| `GET /support/agent/tickets/{id}` | Full ticket including internal notes. |
| `POST /support/agent/tickets/{id}/assign` | Assign (defaults to self). |
| `POST /support/agent/tickets/{id}/reply` · `…/notes` | Public reply / internal note. |
| `PUT /support/agent/tickets/{id}/status` · `…/priority` | Change status / priority (SLA re-applied on priority change). |
| `POST /support/agent/tickets/{id}/escalate` · `…/merge` · `…/tags` | Escalate one level / merge into another ticket / tag. |
| `GET /support/agent/tickets/{id}/ai/summary` · `…/ai/suggest` | AI thread summary / suggested reply. |

## Knowledge base authoring (agent role)

| Method & Path | Purpose |
|---|---|
| `GET`·`POST /support/kb/manage/articles` | List (all statuses) / author a draft. |
| `PUT /support/kb/manage/articles/{id}` | Edit (bumps version). |
| `POST /support/kb/manage/articles/{id}/publish` · `…/archive` | Lifecycle. |
| `DELETE /support/kb/manage/articles/{id}` | Delete. |

## CRM (agent role)

| Method & Path | Purpose |
|---|---|
| `GET /support/crm/customers` | Search profiles (`q`, `segment`). |
| `GET /support/crm/customers/{userId}` | A customer's profile (segment, orders, spend, tickets, tags, notes, insight). |
| `GET /support/crm/customers/{userId}/timeline` | The unified interaction timeline. |
| `POST /support/crm/customers/{userId}/tags` · `PUT …/notes` | Tag / set agent notes. |
| `POST /support/crm/customers/{userId}/insight` | Generate an AI customer insight. |
| `GET /support/crm/segments` | Counts per segment. |

## Admin dashboards & automation (agent role)

| Method & Path | Purpose |
|---|---|
| `GET /support/admin/dashboard?days=` | Queue overview + SLA report + CSAT summary. |
| `GET /support/admin/sla-report?days=` | SLA compliance (breach rate, avg first response). |
| `GET /support/admin/agents?days=` | Per-agent team performance. |
| `GET /support/admin/csat?days=` | CSAT distribution + satisfaction rate. |
| `GET`·`POST /support/admin/rules` · `PUT`·`DELETE /support/admin/rules/{id}` | Manage automation rules. |

## SLA process

- Each priority has an `SlaPolicy` (first-response + resolution minutes), seeded
  from `config/support.php` (`urgent` 30/240, `high` 120/480, `normal` 240/1440,
  `low` 480/2880). A ticket's due-times are computed from its open time.
- **First response** is stamped on the first agent (or bot) public reply;
  **resolution** on transition to `resolved`. `SlaStatus` reports `on_track` /
  `met` / `first_response_breached` / `resolution_breached`.
- **`php artisan support:sla-scan`** (run on a schedule) finds tickets past their
  resolution SLA, publishes `support.sla_breached`, and — when
  `escalate_on_breach` is set — escalates one priority (re-applying the new
  policy so the clock resets), publishing `support.ticket_escalated`.

## Events published

`support.ticket_opened`, `support.ticket_replied`, `support.ticket_resolved`,
`support.ticket_escalated`, `support.sla_breached`, `support.csat_submitted` —
consumed by Notifications/Analytics. The CRM ingests `identity.user_registered`,
`commerce.order_placed` / `marketplace.order_placed`,
`payments.payment_succeeded` / `payments.payment_failed` (config
`timeline_events`).
