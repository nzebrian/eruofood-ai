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
| `Analytics` | Analytics, Business Intelligence & Reporting (Milestone 10): an event-collecting read-model — raw event log + pre-aggregated daily metric buckets — serving executive/operations/finance/vendor dashboards, KPIs with deltas, report generation, and CSV/XLSX/PDF exports + scheduled email reports. **Collects only from domain events** — no module writes into analytics. See its [README](Analytics/README.md). |
| `Admin` | Platform Administration, CMS & Operations (Milestone 11): fine-grained RBAC with nine back-office roles, permission groups, audited impersonation; a CMS (pages/blog/news/legal/help, banners, FAQ, SEO); system configuration, feature flags & maintenance mode; user administration; restaurant/vendor operations (approvals, verification, compliance); a support centre (tickets, live queue, internal notes, escalation); and an append-only audit trail. **Governs via application services and domain events** — writes to other contexts go out as events, reads come through directory ports, and cross-context events feed the audit log by name. See its [README](Admin/README.md). |
| `Search` | Search, Discovery & Recommendation Engine (Milestone 12): an event-fed document index serving global & typed search, autocomplete/suggestions/trending/recent/saved searches, the full filter & sort matrix, a recommendation engine, and search analytics (popular/failed/CTR). Ranking blends full-text relevance with **pgvector** semantic similarity behind a portable pipeline. **No business module searches directly** — modules publish events, Search re-indexes the affected item via read-only source lookups, and all discovery flows through one query port. See its [README](Search/README.md). |
| `Support` | Customer Support, Helpdesk & CRM (Milestone 13): tickets with a status workflow, per-priority **SLA** + escalation + merge, the customer portal and agent workspace, a knowledge base, the CRM (event-fed customer profiles, unified timeline, AI insights, segmentation), declarative **automation rules**, CSAT and admin dashboards. **No business module manages tickets** — the `SupportService` is the one entry point, and the CRM builds itself from published domain events. See its [README](Support/README.md). |
| `Reviews` | Reviews & Ratings (Milestone 14): polymorphic subject reviews (product/food/recipe/vendor/restaurant/rider) with 1–5 stars, verified-purchase (event-fed), a moderation queue with offline/AI content filtering, helpfulness voting, subject-owner responses, the authoritative per-subject rating summary, and review analytics. **No business module stores or aggregates its own reviews** — `ReviewService` is the one entry point, ratings are computed only by the projector, and they flow out via the published rating-summary event. See its [README](Reviews/README.md). |
| `Loyalty` | Loyalty, Rewards & Referrals (Milestone 15): an append-only points ledger with a running balance, membership tiers (recomputed by the projector, published on change), a redeemable rewards catalogue, redemptions that issue vouchers, a referral programme with fraud guards, points expiry, and admin adjustments/analytics. **No business module awards or stores its own points** — `LoyaltyService` is the one entry point, points are earned from published order/review events, and tiers/vouchers flow out via published events. See its [README](Loyalty/README.md). |
| `PublicApi` | Public API, SDK & Developer Platform (Milestone 16): the controlled external surface at `/api/public/v1` (API-key auth, scopes, rate limits, quotas, versioning, standard envelope) plus the JWT developer portal at `/api/v1/developer` (accounts, applications, hashed API keys with rotation/revocation/expiry, signed+retried+idempotent webhooks, usage). **Internal APIs are never exposed** — it is a façade returning transformed resources via a read port over Catalog; analytics and webhooks flow through published events. See its [README](PublicApi/README.md). |

> Remaining business contexts (PublicApi, …) are introduced
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
