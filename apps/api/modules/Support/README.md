# Support Module (`EruoFood\Support`)

The **Customer Support, Helpdesk & CRM** bounded context — the platform's
helpdesk: tickets with SLA and escalation, the customer portal and agent
workspace, a knowledge base, the CRM (profiles, timeline, insights,
segmentation), automation rules, CSAT and admin dashboards.

**No business module manages support tickets.** The `SupportService` is the one
entry point for every interaction, and the CRM builds itself from published
domain events (registrations, orders, payments) — Support reads no other
context's tables and imports no other context's classes. The only inbound
coupling is a config-driven event map keyed by each event's stable name.

> This is the full helpdesk context and supersedes the lightweight ticket view
> inside the Admin module (Milestone 11); they share no namespace or routes.

## What it owns

- **Tickets** (`Ticket`, `TicketMessage`) — a human-readable reference, a
  status workflow (`new → open → pending/on_hold → resolved → closed`, with
  reopen), priorities, categories, channels (web/email/chat/in-app, WhatsApp/
  voice arch-ready), assignment, internal notes, attachments, escalation, merge,
  and full conversation history.
- **SLA** (`SlaPolicy`, `SlaStatus`) — per-priority first-response/resolution
  targets, computed SLA standing, and a `support:sla-scan` breach monitor that
  escalates on breach.
- **Knowledge base** (`Article`) — help articles/FAQs/guides with categories,
  versioning, publish lifecycle and helpfulness voting.
- **CRM** (`CustomerProfile`, `Interaction`) — an event-fed customer projection
  (segment, order & spend totals, ticket count), a unified interaction timeline,
  AI insights and segmentation.
- **Automation** (`AutomationRule`) — declarative trigger → conditions → actions
  rules (auto-routing, tagging, priority, escalation, templated notes) evaluated
  by the `AutomationEngine`.
- **CSAT** (`CsatResponse`) — post-resolution satisfaction ratings and the
  satisfaction dashboard.

## Folder structure

```
modules/Support/src/
├── Domain/                   # Pure PHP — no framework
│   ├── Enum/                 # TicketStatus, TicketPriority, TicketChannel, MessageAuthorType
│   ├── ValueObject/          # Attachment
│   ├── Ticket/               # Ticket, TicketMessage, TicketQuery + repository + stats ports
│   ├── Sla/                  # SlaPolicy, SlaStatus + repository
│   ├── Knowledge/ · Crm/ · Automation/ · Csat/   # aggregates, read models + ports
│   ├── Event/                # ticket opened/replied/resolved/escalated, sla_breached, csat_submitted
│   └── Exception/            # not-found / invalid-state / conflict / not-authorized
├── Application/              # Use cases + ports
│   ├── Port/                 # AiSupportAssistant
│   └── Service/              # Support, Sla, KnowledgeBase, Crm, AutomationEngine, AutomationRule,
│                             #   Csat, SupportAnalytics, AgentAssist, EventTranslator, Presenter
├── Infrastructure/           # Adapters
│   ├── Persistence/          # 7 Eloquent models + repositories, 7 migrations
│   ├── Ai/                   # Heuristic (offline) + AI-backed (AiAdvisor) assistants
│   ├── Event/                # DomainEventSubscriber (bus → CRM translator)
│   ├── Console/              # support:sla-scan
│   ├── Seeder/               # SLA policies, a starter rule, help articles
│   └── Provider/             # SupportServiceProvider (composition root)
└── Interface/                # HTTP (customer/agent/KB/CRM/admin controllers, routes)
```

## Why it's decoupled

- **One service, all interactions.** Every ticket action goes through
  `SupportService`; the aggregate enforces the workflow and SLA milestones.
- **CRM in, by event name.** `EventTranslator` subscribes to the configured
  `timeline_events` and reads ids/amounts from each event's public properties via
  reflection — no imported event classes, no cross-context queries.
- **SLA + automation as data.** Policies and rules are rows; the pure
  `SlaStatus`/`AutomationRule` logic is unit-tested and portable.

See [`docs/api/support-endpoints.md`](../../../../docs/api/support-endpoints.md)
for the endpoints and the SLA process, and
[ADR-0013](../../../../docs/adr/0013-customer-support-crm.md) for the workflows
and architectural decisions.
