# EruoFood AI

Enterprise, AI-powered Nigerian food platform built as a **Modular Monolith**
with **Clean Architecture**, **Domain-Driven Design**, and **SOLID** principles.

> **Status: Phase 2 — Enterprise Project Foundation.**
> This repository currently contains the production-ready *foundation* only:
> structure, tooling, containers, CI/CD, and an architectural skeleton.
> There are **no business features, authentication, recipes, or AI** yet — those
> arrive in later phases (see [`MASTER_PLAN.md`](./MASTER_PLAN.md)).

## Tech stack

| Area | Technology |
|------|-----------|
| Backend | Laravel 12, PHP 8.4 |
| Web | React + TypeScript + Vite |
| Mobile | Flutter (Dart) |
| Data | PostgreSQL 16, Redis 7 |
| Storage | S3-compatible (MinIO locally) |
| Edge | Nginx |
| Runtime | Docker / Docker Compose |
| API | REST (OpenAPI 3.1, GraphQL-ready) |

## Repository layout

```
eruofood-ai/
├── apps/
│   ├── api/            # Laravel 12 modular monolith (Clean Architecture + DDD)
│   ├── web/            # React + TypeScript + Vite (feature-sliced)
│   └── mobile/         # Flutter app (feature-first Clean Architecture)
├── packages/
│   ├── api-contracts/  # OpenAPI 3.1 spec — source of truth for API types
│   └── ui-kit/         # Shared React design system (placeholder)
├── infra/
│   ├── docker/         # Dockerfiles + service configs (php, node, postgres)
│   ├── nginx/          # Reverse-proxy / static-serving config
│   ├── redis/          # Redis config
│   ├── k8s/            # Kubernetes manifests (later phase)
│   └── terraform/      # Infrastructure as Code (later phase)
├── docs/
│   ├── adr/            # Architecture Decision Records
│   ├── api/            # API documentation
│   └── diagrams/       # Architecture diagrams
├── .github/workflows/  # CI/CD pipelines (per app + security)
├── .devcontainer/      # VS Code Dev Container
├── docker-compose.yml  # Base (production-shaped) topology
├── docker-compose.override.yml  # Local dev conveniences
├── Makefile            # Developer control surface (`make help`)
└── MASTER_PLAN.md      # Full architecture blueprint (Phase 1)
```

## Quick start

Prerequisites: Docker + Docker Compose, and `make`.

```bash
make init      # create .env files from the examples
make up        # start the stack (nginx, api, worker, postgres, redis, minio)
make install   # install PHP + JS dependencies
make key       # generate the Laravel app key
```

Then:

- API (through Nginx): <http://localhost:8080/api/v1/health>
- Web (Vite dev server): <http://localhost:5173>
- Mailpit (captured email): <http://localhost:8025>
- MinIO console: <http://localhost:9001>

Run the quality gates exactly as CI does:

```bash
make check     # lint + static analysis + tests across apps
```

## Architecture at a glance

- **Modular Monolith** — one deployable, many isolated **bounded contexts**
  under `apps/api/modules/`. Contexts talk only through published **Contracts**
  or **domain events** — never internals, never cross-context DB joins.
- **Clean Architecture per module** — `Domain → Application → Interface /
  Infrastructure`, dependencies always pointing inward. The `Domain` layer is
  framework-free.
- **Foundation modules present today:** `Shared` (Shared Kernel primitives) and
  `Platform` (a health/status vertical slice demonstrating all four layers).

See [`MASTER_PLAN.md`](./MASTER_PLAN.md) for the complete blueprint and
[`apps/api/modules/README.md`](./apps/api/modules/README.md) for the module
conventions.

## Contributing

- Follow **PSR-12** (PHP), the ESLint/Prettier config (TS), and
  `analysis_options.yaml` (Dart).
- Every PR must pass lint, static analysis, and tests (enforced in CI).
- Record significant decisions as ADRs under `docs/adr/`.
