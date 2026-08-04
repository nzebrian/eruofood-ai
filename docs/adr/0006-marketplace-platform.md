# ADR-0006: Marketplace — one bounded context, guarded state, ports for fees/routing/AI

- **Status:** Accepted
- **Date:** 2026-07-27
- **Deciders:** Engineering, Product

## Context

Milestone 6 adds a full food marketplace: restaurants/vendors, menus, a cart,
checkout, orders, delivery with riders and live tracking, and search. This spans
several concerns that could each be their own context (Ordering, Delivery), but
they change together for this milestone and share a ubiquitous language. Money,
inventory and order state must stay correct, and the platform must integrate
with the AI and Nutrition contexts without coupling.

## Decision

- **One `Marketplace` bounded context** holds Vendor, Menu, Cart, Order,
  Delivery and Rider aggregates (as Catalog holds several). A single **`Vendor`**
  aggregate models restaurants and vendors alike, distinguished by a `type`,
  rather than duplicating overlapping concepts. Ratings/favourites-style reviews
  live here for now and can later move to a dedicated context (contracts +
  events already isolate them).
- **Money as integer minor units** throughout, reusing `Shared\Money`. Prices are
  **captured into order lines at checkout**, so a later menu edit never rewrites a
  placed order — the order is the record of what was agreed.
- **Guarded state machines.** `OrderStatus` and the delivery status permit only
  legal forward transitions (or an early cancel); the aggregates reject illegal
  moves. Checkout is the single place that commits inventory and money.
- **Single-vendor cart.** A cart binds to one vendor; adding from another
  requires clearing it — matching food-delivery UX and keeping one order = one
  vendor.
- **Fees, routing and AI copy are ports.** `DeliveryFeeCalculator`
  (free-over-threshold → vendor zone → distance-based haversine), `RouteOptimizer`
  (nearest-neighbour), and `MenuDescriber` are interfaces; their adapters can be
  swapped for external engines. `MenuDescriber` is implemented over the AI
  module's published `AiAdvisor` **contract** — never AI internals — the same
  cross-context pattern Nutrition uses.
- **Geolocation without PostGIS.** Search uses a lat/lng bounding-box pre-filter
  plus a monotonic squared-distance sort in portable SQL, so it runs on the
  sqlite test DB and Postgres alike; a spatial index/PostGIS is a later
  optimisation behind the same repository method.

## Consequences

- **Positive:** the end-to-end flow (browse → cart → checkout → order → delivery)
  is cohesive and testable; orders are immutable financial records; illegal
  states are impossible by construction; delivery pricing/routing and AI copy are
  replaceable without touching callers; the whole thing runs offline in CI
  (AI via the mock provider through the contract).
- **Negative / trade-offs:** one large context carries several aggregates — if
  Ordering or Delivery grow their own teams/SLAs they should be extracted (the
  events/contracts make that feasible). The nearest-neighbour route optimiser and
  bounding-box geo search are heuristics, adequate for launch but not optimal at
  scale. Reviews duplicate the Catalog's review shape rather than sharing a
  generic reviews context.
- **Follow-ups:** payments/settlement (out of scope here — orders carry totals but
  no charge); a real routing engine (OSRM/Google) and PostGIS geo; websocket
  push for live tracking; extracting Delivery/Ordering if scale demands.

## Alternatives considered

- **Separate Ordering / Delivery / Vendor contexts now** — cleaner long-term
  boundaries but heavy coordination for one milestone where they change together.
  Deferred until scale justifies extraction.
- **Separate Restaurant and Vendor aggregates** — rejected as near-duplicate; a
  `type` on one `Vendor` aggregate is simpler and DRY.
- **Floats for money / a Payments dependency at checkout** — rejected; integer
  minor units avoid rounding errors and checkout stays payment-agnostic.
