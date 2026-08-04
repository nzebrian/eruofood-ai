# ADR-0013: Customer Support — an independent helpdesk + event-fed CRM

- **Status:** Accepted
- **Date:** 2026-07-28
- **Deciders:** Engineering, Product, Customer Operations

## Context

Milestone 13 adds Customer Support, Helpdesk & CRM: ticket management (creation,
assignment, priorities, categories, a status workflow, escalation, internal
notes, attachments, merge, history); customer communication (portal, replies,
CSAT); a knowledge base (articles/FAQs/guides with versioning and helpfulness);
CRM (customer profiles, unified interaction timeline, AI insights, segmentation);
SLA management and automation (auto-routing, SLA monitoring, escalation rules,
AI-assisted summaries/suggested replies); and admin dashboards (queue, SLA,
team performance, CSAT). The hard requirements: an **independent bounded
context**, **no business module manages support tickets directly**, and **all
support interactions go through the Support Domain**.

## Decision

- **A standalone `EruoFood\Support` context that owns every ticket.** No module
  creates, mutates or reads tickets on its own; the `SupportService` is the one
  entry point for every interaction, and the `Ticket` aggregate enforces the
  status workflow (`TicketStatus::canTransitionTo` is the single source of legal
  moves), the first-response/resolution milestones, escalation and merge. This
  coexists with — and supersedes — the lightweight ticket view inside the Admin
  context (Milestone 11); they share no namespace or routes.
- **SLA as a first-class, computed concern.** An `SlaPolicy` per priority
  (seeded from config, editable as its own aggregate) yields a ticket's
  first-response and resolution due-times at open time. `SlaStatus::evaluate` is
  a pure function of the ticket's due-times and milestones, so the same result
  renders in the API and drives the `support:sla-scan` command. The scanner
  finds tickets past their resolution SLA, publishes `SlaBreached`, and (when
  configured) escalates one priority — which re-applies the new policy, pushing
  the clock out so a ticket is not re-escalated every scan (self-limiting, no
  extra "notified" flag).
- **The CRM is built from published events, never by querying other contexts.**
  A config `timeline_events` map ties an external event name (registration,
  order, payment) to an interaction kind; the `EventTranslator` reacts by name,
  reads the user id / amount / reference from the event's public properties via
  reflection (handling stringable value objects), and folds it into the
  `CustomerProfile` (order/spend totals, recomputed segment) and the append-only
  interaction timeline. Support imports no other context's event or model.
- **Automation as data.** `AutomationRule`s (trigger + conditions + actions) are
  rows edited in the admin portal, not code. The `AutomationEngine` runs the
  matching rules for a trigger (e.g. `ticket_opened`) and applies their actions
  (assign/route, set priority, tag, escalate, templated note) to the ticket in
  place; when a rule changes priority the caller re-applies the SLA. Rule
  matching (`AutomationRule::matches`) is pure and unit-tested.
- **AI assist behind a port with an offline default.** `AiSupportAssistant`
  (thread summary, suggested reply, customer insight) defaults to a
  deterministic offline heuristic, so the agent workspace always works; an
  AI-backed adapter over the AI engine's published `AiAdvisor` contract is bound
  when `support.ai_assist` is enabled and fails soft to the heuristic.
- **Persistence shaped to the aggregate.** A ticket and its whole conversation
  (public replies, internal notes, system/automation notes, attachments) persist
  as one row with a JSON `messages` column — the aggregate boundary is the
  consistency boundary, and ticket history comes for free. Reporting lives behind
  a separate `SupportStatsRepository` read port whose adapter computes SLA/agent
  metrics in PHP over a time-bounded row set, so the maths is identical on
  Postgres and sqlite.

## Consequences

- **Positive:** support is fully decoupled — no module touches tickets or the
  CRM, and the CRM assembles itself from events; SLA is computed and testable;
  automation and SLA policies are editable data, not deploys; AI assist upgrades
  by swapping one binding and never breaks the workspace; the pure ranking of
  workflow/SLA/automation logic is unit-tested and portable to sqlite.
- **Negative / trade-offs:** the CRM is eventually consistent with its source
  contexts (it reflects what has been published); storing the conversation as a
  JSON column trades queryable-per-message history for aggregate simplicity
  (acceptable — tickets are read whole); the SLA uses calendar minutes
  (business-hour calendars are architecture-ready); "AI assist" quality depends
  on whether a model-backed adapter is bound.
- **Follow-ups:** business-hours SLA calendars; live chat + WhatsApp/voice
  channel adapters (the channel enum and message model already accommodate them);
  a queued projector for the CRM at high event volume; richer article revision
  history (currently a version counter); and case management grouping related
  tickets.

## Alternatives considered

- **Extend the Admin module's support centre** — rejected: Admin's tickets are a
  minimal back-office convenience; a full helpdesk (SLA, CRM, automation, KB,
  CSAT) is its own bounded context, and folding it into Admin would bloat that
  context and couple support to RBAC/CMS.
- **Let modules create tickets directly / share a tickets table** — rejected
  outright: violates "no module manages tickets" and couples every module to the
  support schema. Modules that want to raise a ticket call the published Support
  service; nothing writes its tables.
- **A third-party helpdesk (Zendesk/Freshdesk) from day one** — rejected for
  launch: another integration and data-residency concern; the port-based design
  lets an external provider later back the repositories without changing callers.
