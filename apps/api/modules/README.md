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

> Business contexts (Catalog, Ordering, Payments, Delivery, …) are introduced in
> their respective roadmap phases (MASTER_PLAN.md §11). None exist yet.

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
