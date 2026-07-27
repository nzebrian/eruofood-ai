# ADR-0007: Commerce — a separate bounded context for general e-commerce & grocery

- **Status:** Accepted
- **Date:** 2026-07-27
- **Deciders:** Engineering, Product

## Context

Milestone 7 ("Marketplace, Grocery & Commerce Platform") adds a general
e-commerce and grocery marketplace: multi-vendor stores, a product catalogue
(general goods and grocery lines), a cart and wishlist, coupons and a discount
engine, tax and shipping, delivery scheduling, order management, returns and
refunds, invoice generation, inventory with warehouses/suppliers and
batch/expiry tracking, promotions and flash sales, and AI-powered shopping
(recommendations, cross/up-sell, a smart assistant and shopping lists).

Phase 6 already delivered a food-delivery `Marketplace` context (restaurants,
menus, single-vendor cart, delivery + riders). The new scope shares vocabulary
("vendor", "cart", "order") but sells **physical goods**, which brings concerns
that context does not have: stock and warehouses, tax and shipping, coupons,
returns/refunds and invoices.

## Decision

- **A new `EruoFood\Commerce` bounded context**, not an extension of
  `Marketplace`. The two share ubiquitous language but not invariants: a food
  order is a single-vendor, delivery/pickup fulfilment with a delivery fee; a
  commerce order is a **multi-store** basket with discount, **tax** and
  **shipping**, and can be **returned & refunded**. Merging them would overload
  every aggregate with mutually-exclusive rules. All Commerce routes live under
  **`/api/v1/commerce/*`** so the two never collide.
- **One money pipeline captured at checkout**: `subtotal → discount → tax →
  shipping → total`, all in **integer minor units** (`Shared\Money`). Prices and
  every charge are snapshotted onto the order, so later catalogue/tax/shipping
  changes never rewrite a placed order — the order is the financial record.
- **Discounts, tax, shipping and pricing are ports.** `DiscountEngine`
  (coupon-based, product promotions already priced into each line),
  `TaxCalculator` (single-rate VAT), `ShippingCalculator` (flat + threshold) and
  `PricingStrategy` (catalogue price after the best active promotion) are
  interfaces. `PricingStrategy` is the explicit **dynamic-pricing seam** —
  architecture-ready without committing to an algorithm.
- **Guarded state machines.** `OrderStatus` (pending → paid → processing →
  shipped → delivered, with cancel/return branches) and `ReturnStatus` only
  permit legal transitions; **checkout** is the single place that commits
  inventory and money, and a refund closes the loop by marking the order
  returned.
- **Inventory as its own aggregate**, keyed by (product, variant SKU), separate
  from the catalogue so warehouse movements and product edits stay independent.
  Stock carries a **low-stock threshold** for alerting and **batches with expiry
  dates**; deductions are **FEFO** (first-expiring, first-out). Barcodes are a
  value object so scanning is architecture-ready.
- **Multi-store cart.** Unlike the food cart's single-vendor rule, a commerce
  basket may span sellers; the order records each line's `store_id`, enabling
  per-seller fulfilment and sales reporting.
- **AI shopping through the published contract.** `CommerceAdvisor`
  (recommendations, cross/up-sell blurbs, the free-text assistant and
  natural-language shopping-list building) is implemented over the AI module's
  `AiAdvisor` **contract** — never internals — the same cross-context pattern
  Marketplace and Nutrition use, and it runs offline in tests via the mock
  provider.

## Consequences

- **Positive:** each context stays cohesive and independently testable; orders
  are immutable financial records with a correct tax/shipping/discount
  breakdown; illegal order/return states are impossible by construction; pricing,
  tax, shipping and discounts are swappable without touching checkout; inventory
  expiry/low-stock is first-class; the whole thing runs offline in CI.
- **Negative / trade-offs:** some concepts (stores/vendors, cart, reviews) now
  exist in two contexts. That duplication is deliberate — the invariants differ —
  but a future "Selling" or "Reviews" shared context could consolidate the
  storefront/review shape if a third seller-facing context appears. Payments and
  settlement are out of scope (orders carry totals and a `paid` status hook but
  capture no funds). Tax is a single VAT rate and shipping is flat/threshold —
  adequate for launch, replaceable behind their ports.
- **Follow-ups:** a payments/settlement context behind the `paid` transition; a
  jurisdiction-aware tax engine and carrier-rate shipping; a real dynamic-pricing
  strategy; and extracting Inventory if warehouse operations grow their own team.

## Alternatives considered

- **Extend the `Marketplace` context** — rejected: it would force one set of
  aggregates to carry two incompatible rule sets (delivery-fee vs.
  tax+shipping+returns, single- vs. multi-vendor), eroding every invariant.
- **Split Commerce further now** (separate Catalog / Inventory / Ordering /
  Promotions contexts) — cleaner long-term boundaries but heavy coordination for
  one milestone where they change together; deferred until scale justifies it
  (events/contracts make later extraction feasible).
- **Floats for money, or a Payments dependency at checkout** — rejected; integer
  minor units avoid rounding errors and checkout stays payment-agnostic.
