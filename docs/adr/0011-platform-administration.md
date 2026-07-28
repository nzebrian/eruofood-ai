# ADR-0011: Platform Administration — an independent bounded context that governs via services and events

- **Status:** Accepted
- **Date:** 2026-07-28
- **Deciders:** Engineering, Product, Operations, Compliance

## Context

Milestone 11 adds Platform Administration, CMS & Operations: nine back-office
roles with fine-grained RBAC and permission groups; user impersonation with
audit; a CMS (pages, blog, news, legal, help centre, FAQ, banners, SEO); system
configuration (app/AI/payment/notification settings, feature flags, maintenance
mode, regional settings); user administration (search, suspend, verify);
restaurant & vendor operations (approvals, verification, compliance); a support
centre (tickets, live queue, internal notes, escalation); and audit & compliance
(activity, security and login history, config-change and data-access logs). The
hard requirements: an **independent bounded context** that communicates with
other modules **through application services and domain events** — never by
reaching into their internals or tables.

## Decision

- **A standalone `EruoFood\Admin` context that owns its own RBAC.** Admin does
  not reuse Identity's roles as its authority. It keeps an `AdminAccount`
  aggregate keyed by the Identity user id (a soft reference) with its own roles
  and per-account permission grants. A framework-free `Permission` catalogue
  holds the fine-grained permissions, the role→permissions map and the permission
  groups — one source of truth for both authorisation checks and the admin UI.
- **Two authorisation tiers.** The coarse gate is route middleware (`auth.jwt`);
  the fine-grained check is per-action in the controller via a
  `PermissionService`, which throws `AdminNotAuthorized` (→ 403). Super admins
  bypass the map. Bootstrapping is config-driven: an explicit super-admin id
  allow-list, plus an opt-in that treats any Identity platform-admin as a super
  admin, so the first administrator exists before any account is granted.
- **Writes to other contexts go out as domain events; reads come in through
  ports.** Suspending a user publishes `AdminUserSuspended` (Identity reacts and
  revokes sessions); a vendor decision publishes `VendorApprovalDecided`
  (Marketplace flips the vendor's own status); impersonation and broadcasts are
  events too. To *display* users and vendors, Admin reads through `UserDirectory`
  and `VendorDirectory` ports whose adapters do soft, read-only lookups over the
  `identity_users` / `marketplace_vendors` tables — no cross-context joins, no
  writes.
- **An append-only audit trail, fed two ways.** Every mutating admin service
  records an `AuditLogEntry` (immutable; no update/delete) under a category.
  Cross-context security/activity events (registrations, password changes,
  payment failures, vendor verification) are captured by an `EventAuditTranslator`
  that subscribes to the shared bus **by event name** and reads the subject id and
  data from the event's public properties via reflection — the same decoupled
  pattern Notifications and Analytics use, applied as a compliance sink. Adding an
  audited event is a one-line config edit.
- **Tickets as an aggregate with embedded messages.** A `Ticket` owns its
  conversation of public replies and internal notes; internal notes are filtered
  out of the requester-facing view in the domain. Messages persist as a JSON
  column on the ticket row, keeping the aggregate boundary intact.

## Consequences

- **Positive:** Admin never couples to another context's internals; moderation
  and approvals are one-way events other contexts opt into; the audit trail is
  tamper-resistant (append-only) and fed automatically from across the platform;
  RBAC is self-contained and testable (grant a role, assert a 403/200); new
  audited events and new permissions are config/catalogue edits.
- **Negative / trade-offs:** because effects are events, an admin action and the
  target context's reaction are eventually consistent, not transactional — the
  audit entry is the durable record of intent. The directory adapters read other
  contexts' tables directly (guarded by existence checks): a soft reference that
  trades a hard boundary for avoiding a synchronous cross-context query API.
  Feature flags and settings are read-modify-write and assume a single writer,
  which is correct for admin edits.
- **Follow-ups:** a scoped, time-boxed impersonation token minted by Identity off
  the published event; richer CMS versioning/scheduling; a settings cache with
  event-driven invalidation on `SettingChanged`; SLA timers and auto-escalation
  in the support queue; exportable audit reports.

## Alternatives considered

- **Reuse Identity's RBAC as the admin authority** — rejected: back-office
  permissions are far finer than app roles, and coupling admin authorisation to
  Identity's schema would bleed one context's model into another.
- **Let Admin write directly into other contexts' tables** (suspend a user by
  updating `identity_users`, approve a vendor by updating `marketplace_vendors`)
  — rejected outright: violates the "services and events only" requirement and
  couples Admin to every other schema.
- **A shared audit table written by every module** — rejected: makes audit a
  cross-cutting write dependency; the event-fed, Admin-owned trail keeps
  collection decoupled and the write path one-directional.
