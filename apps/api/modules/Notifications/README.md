# Notifications Module (`EruoFood\Notifications`)

The **Notifications, Messaging & Real-Time Communication** bounded context —
every message the platform sends a user: transactional and promotional
notifications across channels, an in-app notification centre, real-time chat
between customers/vendors/riders/admins, admin broadcasts, and the WebSocket
plumbing for live updates, typing indicators and presence.

**No business module sends notifications directly.** Modules publish domain
events; this context subscribes to them on the shared event bus and turns them
into notifications. The only inbound coupling is a config-driven map keyed by
the event's stable name — the notification engine never imports another
context's classes, and no other context imports this one.

## What it owns

- **Notification engine** (`Notification`, `NotificationTemplate`,
  `NotificationPreference`) — one queued, rendered notification per eligible
  channel, with a guarded delivery lifecycle, a retry mechanism, scheduled
  sends, quiet-hours deferral, per-category channel preferences, and history.
- **Channels** — Email, SMS, Push, In-App, plus architecture-ready **WhatsApp**
  and **Telegram**. Each is a `ChannelSender` adapter; only the enabled ones are
  wired. In-app is always on (it *is* the notification centre).
- **Messaging** (`Conversation`, `Message`) — customer ↔ restaurant/vendor/rider,
  admin ↔ user and group announcements, with **read receipts**, **typing
  indicators**, **file attachments** and architecture-ready **voice** messages.
- **Real-time** (`RealtimeBroadcaster`, presence) — WebSocket broadcasts for
  live order/delivery updates, live chat, typing and **presence**; a log
  broadcaster runs offline, a Reverb/broadcasting adapter is architecture-ready.
- **Broadcasts** (`Broadcast`) — the admin **campaign manager**: fan a message
  out to an audience segment across channels (still honouring preferences).

## Folder structure

```
modules/Notifications/src/
├── Domain/                   # Pure PHP — no framework
│   ├── Enum/                 # Channel, Category, Status, MessageType, ConversationType, Priority, Presence
│   ├── ValueObject/          # RenderedContent, QuietHours, Attachment
│   ├── Notification/ Template/ Preference/ Messaging/ Broadcast/  # aggregates + ports
│   ├── Event/                # NotificationDispatched, MessageSent
│   └── Exception/            # not-found / invalid-state / conflict / not-authorized
├── Application/              # Use cases + ports
│   ├── Port/                 # ChannelSender, RealtimeBroadcaster, PresenceRepository
│   ├── DTO/                  # DeliveryOutcome, DeliveryReport
│   └── Service/              # Notification, ChannelDispatcher, EventTranslator, Preference,
│                             #   Messaging, Realtime, Broadcast, Template, DeliveryReport, Presenter
├── Infrastructure/           # Adapters
│   ├── Persistence/          # 7 Eloquent models + repositories, 7 migrations
│   ├── Channel/              # Email/Sms/Push/InApp/WhatsApp/Telegram senders
│   ├── Realtime/             # Log + broadcasting (Reverb-ready) broadcasters
│   ├── Event/                # DomainEventSubscriber (bus → translator)
│   ├── Broadcast/            # IdentityAudienceProvider
│   ├── Seeder/               # default templates
│   └── Provider/             # NotificationsServiceProvider (composition root)
└── Interface/                # HTTP (controllers, requests, routes)
```

## The event-driven trigger (why it's decoupled)

The shared `EventBus` dispatches every domain event by its stable
`eventName()` string with the event object as payload. On boot, this module's
`DomainEventSubscriber` registers a listener for each event name in
`config/notifications.php → event_map`. When any module publishes a mapped event
(e.g. `payments.payment_succeeded`, `commerce.order_placed`,
`identity.user_registered`), the `EventTranslator` reads the recipient id and
data from the event's **public properties** (`get_object_vars`) — never a typed
import — and calls the notification engine. Adding a new notifying event is a
one-line config entry.

## Notification types covered

Registration, login/2FA & password alerts, order updates, payment & wallet
alerts, delivery updates, promotional campaigns, AI recommendations, meal &
nutrition reminders, and admin broadcasts — all via the event map or the
broadcast/campaign manager.

## User preferences

Enable/disable channels per category, quiet hours (with a cross-midnight window;
transactional & high-priority notifications ignore them), language, and a
per-day frequency cap. In-app is never fully disabled — the centre always keeps
the record.

## Persistence

Seven `notifications_*` tables. Other contexts are referenced by ID only. Seed
default templates:

```
php artisan db:seed --class="EruoFood\Notifications\Infrastructure\Seeder\NotificationsSeeder"
```

## Error → HTTP mapping

`NOTIFICATIONS_RESOURCE_NOT_FOUND` → 404, `NOTIFICATIONS_NOT_AUTHORIZED` → 403
(e.g. not a conversation participant), `NOTIFICATIONS_INVALID_STATE` → 422
(illegal delivery transition), `NOTIFICATIONS_CONFLICT` → 409.

## Testing

- **Unit** — the notification delivery lifecycle + retry cap + read-once + due
  scheduling, preference channel resolution + cross-midnight quiet hours, and
  conversation participation + message read receipts.
- **Feature** — a published domain event becomes an in-app notification (proving
  the decoupled trigger), preference-driven suppression, mark-read/unread-count,
  the full chat flow (start → send → the other participant is notified & reads,
  non-participant blocked), and an admin broadcast to a segment. All offline via
  the log channel/broadcaster.

See [docs/api/notifications-endpoints.md](../../../../docs/api/notifications-endpoints.md)
and [ADR-0009](../../../../docs/adr/0009-notifications-platform.md).
