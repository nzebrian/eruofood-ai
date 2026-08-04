# ADR-0001: Adopt a Modular Monolith with DDD + Clean Architecture

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Engineering, Architecture

## Context

EruoFood AI must reach production quickly while remaining able to scale to
millions of users. A microservices-first approach adds distributed-systems
overhead (network failure modes, eventual consistency everywhere, deployment
sprawl) before we have the domain understanding or scale to justify it.

## Decision

We will build a **Modular Monolith**: one deployable application composed of
isolated **bounded contexts** (DDD), each internally structured with **Clean
Architecture** layers (Domain → Application → Interface/Infrastructure).
Modules communicate only through **published Contracts** or **domain events** —
never through internals or cross-context database joins.

## Consequences

- **Positive:** fast delivery, strong local consistency (shared transactions
  where correctness demands), one CI/CD pipeline, low operational cost.
- **Positive:** each context can later be extracted into a service as a refactor
  (contracts + events are already the seams), not a rewrite.
- **Negative / trade-offs:** module boundaries must be enforced by discipline,
  code review, and static analysis — they are not enforced by process isolation.
- **Follow-ups:** ADR-0002 (layering rules), tooling to assert Domain stays
  framework-free.

## Alternatives considered

- **Microservices from day one** — rejected: premature complexity and cost.
- **Traditional layered monolith (no contexts)** — rejected: leads to a big ball
  of mud and blocks future extraction.
