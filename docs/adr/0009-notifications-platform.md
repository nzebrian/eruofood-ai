# ADR-0009: Notifications — an event-subscribing context, decoupled by event name

- **Status:** Accepted
- **Date:** 2026-07-27
- **Deciders:** Engineering, Product

## Context

Milestone 9 adds Notifications, Messaging & Real-Time Communication: multi-channel
notifications (email, SMS, push, in-app, and architecture-ready WhatsApp/Telegram),
an in-app notification centre with preferences and quiet hours, real-time chat
with read receipts/typing/attachments, admin broadcasts, and WebSocket presence
and live updates. The hard requirement: this must be an **independent bounded
context**, and **no business module may send notifications directly — everything
is triggered through domain events**.

## Decision

- **A standalone `EruoFood\Notifications` context that only ever *reacts*.** It
  exposes no contract other contexts call to "send a notification". Instead it
  subscribes to the shared event bus. Every business action that should notify a
  user already publishes a domain event (registration, order placed, payment
  succeeded/failed, wallet credited, settlement, …); Notifications listens.
- **Decoupling by event *name*, not by type.** The `EventBus` dispatches each
  event by its stable `eventName()` string with the event object as payload. A
  config map (`event_map`) associates each event name with a category, template,
  channels and the ordered property names to try for the recipient. On boot a
  `DomainEventSubscriber` registers a listener per mapped name; the
  `EventTranslator` reads the recipient id and data from the event's **public
  properties via `get_object_vars`** — so Notifications never imports another
  context's event class, and adding a notifying event is a one-line config edit.
- **Channels behind a port, resolved by a dispatcher.** Each channel (email,
  SMS, push, in-app, WhatsApp, Telegram) is a `ChannelSender`; the
  `ChannelDispatcher` holds only the enabled ones. In-app is always on because
  the persisted notification row *is* the notification-centre entry. WhatsApp and
  Telegram ship disabled but wired.
- **Preferences and quiet hours in the engine, not the callers.** The engine
  filters channels by the user's per-category preferences and defers
  quiet-hours-respecting categories (promo/AI/nutrition) out of the window;
  transactional and high-priority notifications are never deferred. The delivery
  lifecycle is a guarded state machine with an attempt counter for retries and a
  `scheduled_for` for scheduled sends.
- **Real-time behind a broadcaster port.** Live order/delivery updates, chat,
  typing and presence go through a `RealtimeBroadcaster`. The default logs
  (offline-safe for tests); a Laravel Reverb/broadcasting adapter is
  architecture-ready and publishes to the same channel names
  (`user.{id}`, `conversation.{id}`, `presence`). Connection recovery is a
  client concern (re-subscribe); the server reports current presence on demand.
- **Messaging as its own aggregates.** Conversations guard participation; sending
  a message pushes a real-time event *and* raises an in-app notification for the
  other participants through the same engine — so even chat never touches a
  channel directly.

## Consequences

- **Positive:** business modules stay ignorant of notifications; new notifying
  events are config, not code; channels/transport are swappable; preferences and
  quiet hours are enforced in one place; the whole system (including the
  event → notification trigger) runs offline in CI via the log channel and
  broadcaster.
- **Negative / trade-offs:** reflection-based recipient extraction relies on the
  event exposing a recognisable public property; events without one are ignored
  (acceptable — those are admin/broadcast candidates). Notifications are
  dispatched synchronously in-process for simplicity and testability; production
  should queue the listener (the `EventBus` already allows queued listeners) and
  run the `dispatchDue`/`retryFailed` loops on a schedule. The default channel
  senders log rather than integrate a real ESP/SMS/push provider — those are
  drop-in adapters behind the port. Presence is heartbeat-based, not
  socket-connection-based.
- **Follow-ups:** real ESP/SMS/FCM adapters; a Reverb deployment for true
  WebSockets; queued dispatch + scheduled workers for the notification queue and
  retries; per-user frequency-cap enforcement; WhatsApp/Telegram credentials.

## Alternatives considered

- **A `Notifier` contract other modules call** — rejected: it violates the
  "no direct sends / events only" requirement and couples every module to the
  notification API.
- **Importing each event class and typed listeners** — cleaner typing but
  couples Notifications to every other context's internals; the event-name +
  `get_object_vars` approach keeps the dependency to a config map.
- **A shared notifications table other modules write to** — rejected: it makes
  notification concerns leak into every module and bypasses preferences/quiet
  hours.
