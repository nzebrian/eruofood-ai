# Platform Administration, CMS & Operations — API Endpoints

Base URL: `/api/v1`. All paths are under **`/admin`** and require a bearer
token (`auth.jwt`). Beyond authentication, **every action asserts a fine-grained
permission** in the controller; a caller without it gets `403`
(`ADMIN_NOT_AUTHORIZED`). Super administrators (config allow-list or an Identity
platform-admin, when enabled) bypass the permission map. The Admin context talks
to other modules **only through application services and domain events** — it
never writes their tables. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Roles & permissions

Nine roles — `super_admin`, `admin`, `moderator`, `content_manager`,
`customer_support`, `finance_manager`, `restaurant_manager`, `vendor_manager`,
`operations_manager` — map to a fine-grained permission catalogue grouped by
prefix: `rbac.manage`, `rbac.impersonate`, `content.manage`, `config.read`,
`config.write`, `users.read`, `users.moderate`, `ops.read`, `ops.approve`,
`support.read`, `support.manage`, `finance.read`, `audit.read`.

## Role & Permission Management (RBAC)

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET /admin/permissions` | `rbac.manage` | The permission catalogue, groups and role map. |
| `GET /admin/accounts` | `rbac.manage` | List back-office accounts. |
| `GET /admin/accounts/{userId}` | `rbac.manage` | A single account. |
| `PUT /admin/accounts/{userId}/roles` | `rbac.manage` | Set an account's roles. |
| `POST` · `DELETE /admin/accounts/{userId}/permissions` | `rbac.manage` | Grant / revoke an individual permission. |
| `POST /admin/accounts/{userId}/suspend` · `…/activate` | `rbac.manage` | Suspend / reactivate an admin account. |
| `POST /admin/impersonations` | `rbac.impersonate` | Start impersonating a user (audited; publishes `admin.impersonation_started`). |
| `POST /admin/impersonations/{id}/end` | `rbac.impersonate` | End an impersonation session. |

## Content Management (CMS)

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET`·`POST /admin/cms/pages` | `content.manage` | List / author content (`page`\|`blog`\|`news`\|`legal`\|`help_article`). |
| `GET`·`PUT /admin/cms/pages/{id}` | `content.manage` | Read / edit a page. |
| `POST /admin/cms/pages/{id}/publish` · `…/unpublish` · `…/archive` | `content.manage` | Lifecycle transitions. |
| `GET`·`POST /admin/cms/banners` | `content.manage` | List / create dynamic banners. |
| `PUT /admin/cms/banners/{id}/active` · `DELETE …` | `content.manage` | Activate/deactivate / delete a banner. |
| `GET`·`POST /admin/cms/faqs` · `PUT`·`DELETE /admin/cms/faqs/{id}` | `content.manage` | Manage FAQ / help entries. |

## System Configuration

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET /admin/settings` | `config.read` | List settings (optionally by `group`; secrets redacted). |
| `PUT /admin/settings/{key}` | `config.write` | Update a setting (publishes `admin.setting_changed`). |
| `GET /admin/flags` · `PUT /admin/flags/{key}` | `config.read` / `config.write` | Read / toggle feature flags. |
| `GET`·`PUT /admin/maintenance` | `config.read` / `config.write` | Read / toggle maintenance mode (publishes `admin.maintenance_mode_toggled`). |

## User Administration

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET /admin/users` | `users.read` | Search users (`q`, `status`; read via the Identity directory port). |
| `GET /admin/users/{userId}` | `users.read` | A single user summary. |
| `POST /admin/users/{userId}/suspend` | `users.moderate` | Suspend (publishes `admin.user_suspended`; Identity revokes sessions). |
| `POST /admin/users/{userId}/reinstate` | `users.moderate` | Reinstate (publishes `admin.user_reinstated`). |

## Restaurant & Vendor Operations

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET`·`POST /admin/operations/approvals` | `ops.read` | The approval queue / submit an onboarding, verification or compliance request. |
| `GET /admin/operations/approvals/{id}` | `ops.read` | A single request. |
| `POST /admin/operations/approvals/{id}/approve` · `…/reject` | `ops.approve` | Decide (publishes `admin.vendor_approval_decided`). |
| `GET /admin/operations/vendors` | `ops.read` | Search vendors (via the Marketplace directory port). |

## Support Centre

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET`·`POST /admin/support/tickets` | `support.read` / `support.manage` | The live queue (most urgent first) / open a ticket. |
| `GET /admin/support/tickets/{id}` | `support.read` | A ticket with its full thread. |
| `POST /admin/support/tickets/{id}/assign` | `support.manage` | Assign to an agent. |
| `POST /admin/support/tickets/{id}/reply` · `…/notes` | `support.manage` | Public reply / internal note. |
| `POST /admin/support/tickets/{id}/escalate` | `support.manage` | Raise the priority. |
| `POST /admin/support/tickets/{id}/resolve` · `…/close` | `support.manage` | Resolve / close. |

## Audit & Compliance

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET /admin/audit` | `audit.read` | The append-only audit trail, filterable by `category`, `actor_id`, `subject_type`, `subject_id`. |

Audit categories: `auth`, `security`, `config`, `content`, `users`,
`operations`, `support`, `rbac`, `data_access`. Cross-context security/activity
events (registrations, password changes, payment failures, vendor verification)
are ingested automatically by an event subscriber — see the config `audit_events`
map.
