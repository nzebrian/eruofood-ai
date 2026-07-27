# Modules — Bounded Contexts

Each subdirectory here is a **bounded context** (DDD) and a self-contained
**Clean Architecture** slice. Modules are the unit of modularity in the
Modular Monolith: one deployable, many isolated contexts that communicate only
through **published Contracts** (interfaces + DTOs) or **domain events** — never
by touching another module's internals or tables.

## Foundation modules present in Phase 2

| Module | Purpose |
|--------|---------|
| `Shared` | Shared Kernel — framework-agnostic primitives (Money, Uuid, Clock, EventBus, AggregateRoot, DomainEvent, DomainException) used by every context. Deliberately minimal. |
| `Platform` | Operational/foundation context. Ships the health/status vertical slice that exercises all four layers end-to-end. Contains **no business logic**. |
| `Identity` | Identity & Access (Milestone 2): authentication, user management, roles & permissions, sessions, 2FA, audit. See its [README](Identity/README.md). |
| `Catalog` | Nigerian Food Database & Recipes (Milestone 3): foods, categories, ingredients, recipes, reviews, favourites, versioning. See its [README](Catalog/README.md). |
| `Ai` | AI Engine & Intelligent Recipe Generation (Milestone 4): multi-provider AI service layer, versioned prompt system, recipe generation, cooking assistant + chat history, usage & cost tracking. See its [README](Ai/README.md). |
| `Nutrition` | Nutrition, Health & Personalisation (Milestone 5): nutrition database, health profiles, BMI/BMR/TDEE calculators, food diary, meal plans + shopping lists, progress tracking, and AI personalisation (via the Ai module's contract). See its [README](Nutrition/README.md). |
| `Marketplace` | Restaurant, Vendor & Food Business Platform (Milestone 6): vendors/restaurants, menus, cart, checkout, orders, delivery + riders with live tracking, search, and AI menu descriptions (via the Ai module's contract). See its [README](Marketplace/README.md). |
| `Commerce` | Marketplace, Grocery & Commerce Platform (Milestone 7): multi-vendor stores, a general + grocery product catalogue, multi-store cart & wishlist, discount/tax/shipping-aware checkout, orders with returns & invoices, inventory with warehouses/suppliers & batch-expiry tracking, promotions & coupons, and an AI shopping assistant (via the Ai module's contract). See its [README](Commerce/README.md). |
| `Payments` | Payments, Wallet & Financial Services (Milestone 8): multi-provider payments (Paystack/Flutterwave/Moniepoint/Stripe/PayPal) behind a provider-abstraction factory, a double-entry transaction ledger, wallets for every account type, refunds, commission, settlements & payouts, tokenised saved methods, subscriptions, and signature-verified idempotent webhooks. **Decoupled from Order** — integrates via a published contract in and domain events out. See its [README](Payments/README.md). |
| `Notifications` | Notifications, Messaging & Real-Time Communication (Milestone 9): multi-channel notification engine (email/SMS/push/in-app, WhatsApp/Telegram arch-ready) with templates, per-category preferences, quiet hours, retries & scheduling; real-time chat with read receipts/typing/attachments; admin broadcasts; and WebSocket presence/live updates. **Fully event-driven** — no module sends notifications directly; it subscribes to published domain events by name. See its [README](Notifications/README.md). |

> Remaining business contexts (Analytics, Search, …) are introduced
> in their respective roadmap phases (MASTER_PLAN.md §11).

## Standard module layout

```
<Context>/
├── src/
│   ├── Domain/            # Layer 1 — pure PHP: entities, value objects,
│   │                      #   domain events, domain services, repository ports,
│   │                      #   specifications, exceptions. No framework imports.
│   ├── Application/       # Layer 2 — use cases: Commands/Queries + handlers,
│   │                      #   DTOs/read models, ports (gateway interfaces).
│   ├── Infrastructure/    # Layer 4 — adapters: Eloquent repositories, external
│   │                      #   gateways, migrations, and the module ServiceProvider
│   │                      #   (DI bindings, route/migration/event registration).
│   ├── Interface/         # Layer 3 — delivery: HTTP controllers, form requests,
│   │                      #   API resources, console commands, queued listeners,
│   │                      #   and the module's routes.php.
│   └── Contracts/         # The module's PUBLIC API: interfaces + DTOs other
│                          #   modules are allowed to depend on.
└── tests/
    ├── Unit/              # Fast, framework-free domain/application tests.
    └── Feature/           # HTTP/integration tests that boot the application.
```

## Rules (enforced in code review + static analysis)

1. **Dependencies point inward:** Interface/Infrastructure → Application → Domain.
2. **Domain is framework-free:** no Laravel/Eloquent imports under `Domain/`.
3. **No cross-module internals:** depend on another context's `Contracts/` only.
4. **No cross-context DB joins:** reference other contexts by ID (soft reference).
5. **Register the module** in `bootstrap/providers.php` and map its namespace in
   `composer.json` (`psr-4`).
