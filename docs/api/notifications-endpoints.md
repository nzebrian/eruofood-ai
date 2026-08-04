# Notifications, Messaging & Real-Time Communication — API Endpoints

Base URL: `/api/v1`. All paths are under **`/notifications`** and require a
bearer token; the admin sub-tree needs the `admin` role. Notifications
themselves are **not created via the API** — business modules publish domain
events and this context reacts (see the event-driven trigger below). Full
schema: [`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Notification centre (customer)

| Method & Path | Purpose |
|---|---|
| `GET /notifications` | The in-app notification centre (`unread` filter, paginated). |
| `GET /notifications/unread-count` | Unread count (for the badge). |
| `POST /notifications/{id}/read` | Mark one read. |
| `POST /notifications/read-all` | Mark all read. |

## Preferences (customer)

| Method & Path | Purpose |
|---|---|
| `GET /notifications/preferences` | Current preferences. |
| `PUT /notifications/preferences` | Update language / daily frequency cap. |
| `PUT /notifications/preferences/channels` | Set enabled channels for a category (`category`, `channels[]`). |
| `PUT /notifications/preferences/quiet-hours` | Set quiet hours (`enabled`, `start`, `end` — HH:MM). |

## Messaging / chat (customer)

| Method & Path | Purpose |
|---|---|
| `GET /notifications/conversations` | The caller's inbox. |
| `POST /notifications/conversations` | Start a conversation (`type`, `participant_ids[]`, `subject?`, `context_ref?`). |
| `GET /notifications/conversations/{id}/messages` | A conversation's messages (participants only). |
| `POST /notifications/conversations/{id}/messages` | Send a message (`body` and/or `attachments[]`; `type` text\|file\|voice). |
| `POST /notifications/conversations/{id}/typing` | Broadcast a typing indicator (`typing`). |
| `POST /notifications/messages/{messageId}/read` | Mark a message read (read receipt). |

## Real-time presence (customer)

| Method & Path | Purpose |
|---|---|
| `POST /notifications/presence/heartbeat` | Report presence (`status`: online\|away\|offline). |
| `GET /notifications/presence` | Presence of one or more users (`user_ids[]`). |

WebSocket transport (architecture-ready, Laravel Reverb) publishes on the
channels `user.{id}` (notifications & live order/delivery updates),
`conversation.{id}` (messages, read receipts, typing) and `presence`.

## Admin (role: admin)

| Method & Path | Purpose |
|---|---|
| `GET /notifications/admin/broadcasts` · `POST …` | List / create a broadcast campaign. |
| `POST /notifications/admin/broadcasts/{id}/send` | Send a broadcast to its segment (`users:id1,id2` or `all`/`active`). |
| `GET /notifications/admin/templates` · `POST …` | List / create-or-update a template. |
| `GET /notifications/admin/report` | Delivery analytics (totals by status & channel). |

## The event-driven trigger (no HTTP)

Business modules never call this context. They publish domain events on the
shared bus; the Notifications context subscribes by the event's stable name and
turns it into notifications, using `config/notifications.php → event_map`. Mapped
events include: `identity.user_registered`, `identity.password_changed`,
`identity.two_factor_enabled`, `commerce.order_placed`,
`marketplace.order_placed`, `payments.payment_succeeded`,
`payments.payment_failed`, `payments.wallet_credited`,
`payments.wallet_low_balance`, `payments.settlement_completed`,
`nutrition.health_profile_updated`. Add a notifying event by adding one map entry.

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `NOTIFICATIONS_RESOURCE_NOT_FOUND` | 404 | Notification/template/conversation/message missing. |
| `NOTIFICATIONS_NOT_AUTHORIZED` | 403 | Not a participant / not the owner. |
| `NOTIFICATIONS_INVALID_STATE` | 422 | Illegal delivery-status transition. |
| `NOTIFICATIONS_CONFLICT` | 409 | Duplicate template key. |
