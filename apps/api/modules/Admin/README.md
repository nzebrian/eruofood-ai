# Admin Module (`EruoFood\Admin`)

The **Platform Administration, CMS & Operations** bounded context — the
back-office: role-based administration, content management, system
configuration, user administration, restaurant/vendor operations, the support
centre and the audit trail.

**Admin governs the platform without reaching into it.** It owns its own
fine-grained RBAC; it *writes* to other contexts only by publishing domain
events (a suspension, an approval, an impersonation, a setting change), and it
*reads* other contexts only through directory ports whose adapters do soft,
read-only lookups — never a cross-context join, never a foreign write. The one
inbound coupling is a config-driven audit map keyed by each event's stable name,
so the compliance trail fills automatically without importing any other
context's classes.

## What it owns

- **RBAC** (`AdminAccount`, `Permission`, `Impersonation`) — nine roles, a
  fine-grained permission catalogue with groups, per-account extra grants,
  account suspension, and audited user impersonation. Super admins bypass the
  map; bootstrapping is config-driven (an id allow-list plus an opt-in for
  Identity platform-admins).
- **CMS** (`CmsPage`, `Banner`, `FaqItem`) — pages, blog, news, legal documents
  and help articles with a draft → published → archived lifecycle, SEO metadata,
  dynamic time-boxed banners, and FAQ/help entries.
- **Configuration** (`Setting`, `FeatureFlag`) — grouped settings
  (app/AI/payment/notification/regional), secret redaction, feature flags, and
  maintenance mode.
- **Operations** (`ApprovalRequest`) — vendor/restaurant onboarding, business
  verification and compliance reviews; a decision publishes an event the owning
  context consumes.
- **Support** (`Ticket`, `TicketMessage`) — the live ticket queue (most urgent
  first), public replies, staff-only internal notes, assignment, escalation and
  resolution.
- **Audit** (`AuditLogEntry`) — an append-only activity/security/compliance
  trail (no update, no delete), written by every mutating admin service and fed
  cross-context events by an event subscriber.

## Folder structure

```
modules/Admin/src/
├── Domain/                   # Pure PHP — no framework
│   ├── Enum/                 # AdminRole, AccountStatus, AuditCategory
│   ├── Rbac/                 # AdminAccount, Permission, Impersonation + ports
│   ├── Cms/                  # CmsPage, Banner, FaqItem, SeoMetadata, ContentType + ports
│   ├── Config/               # Setting, FeatureFlag + ports
│   ├── Operations/           # ApprovalRequest, ApprovalKind/Status + port
│   ├── Support/              # Ticket, TicketMessage, TicketStatus/Priority + port
│   ├── Audit/                # AuditLogEntry, AuditQuery + port
│   ├── Event/                # AdminUserSuspended/Reinstated, VendorApprovalDecided,
│   │                         #   SettingChanged, MaintenanceModeToggled, ImpersonationStarted, BroadcastRequested
│   └── Exception/            # not-found / invalid-state / conflict / not-authorized
├── Application/              # Use cases + ports
│   ├── Port/                 # UserDirectory, VendorDirectory
│   ├── DTO/                  # UserSummary, VendorSummary
│   └── Service/              # Permission, AdminAccount, Impersonation, Cms, Setting,
│                             #   UserAdmin, Operations, Support, Audit, EventAuditTranslator, Presenter
├── Infrastructure/           # Adapters
│   ├── Persistence/          # 10 Eloquent models + repositories, 10 migrations
│   ├── Directory/            # IdentityUserDirectory, MarketplaceVendorDirectory (soft reads)
│   ├── Event/                # DomainEventSubscriber (bus → audit translator)
│   ├── Seeder/               # default settings + feature flags
│   └── Provider/             # AdminServiceProvider (composition root)
└── Interface/                # HTTP (controllers, permission concern, routes)
```

## Why it's decoupled

- **Writes go out as events.** `UserAdminService` publishes `AdminUserSuspended`;
  `OperationsService` publishes `VendorApprovalDecided`; `SettingService`
  publishes `SettingChanged` / `MaintenanceModeToggled`. Identity, Marketplace and
  caches react on their own terms. Admin holds no foreign write path.
- **Reads come through ports.** `UserDirectory` / `VendorDirectory` adapters read
  the `identity_users` / `marketplace_vendors` tables read-only (guarded by
  existence checks) — a soft reference, not a join.
- **Audit ingests by event name.** `EventAuditTranslator` subscribes to the
  configured `audit_events` and reads each event's public properties via
  reflection — never importing another context's event class.

## Authorisation

Two tiers: the route middleware `auth.jwt` proves identity; each controller
action then asserts a specific permission via `PermissionService` (→ `403`
`ADMIN_NOT_AUTHORIZED` on failure). See
[`docs/api/admin-endpoints.md`](../../../../docs/api/admin-endpoints.md) for the
per-action permission map and [ADR-0011](../../../../docs/adr/0011-platform-administration.md)
for the design rationale.
