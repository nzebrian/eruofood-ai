# Commerce Module (`EruoFood\Commerce`)

The **Marketplace, Grocery & Commerce Platform** bounded context — a general
e-commerce and grocery marketplace: multi-vendor stores and a product catalogue
(general goods **and** grocery lines), a multi-store cart and wishlist, a
discount/tax/shipping-aware checkout, orders with returns & invoices, inventory
with warehouses/suppliers/batch-expiry tracking, promotions & coupons, and an
AI shopping assistant. Distinct from the food-delivery
[`Marketplace`](../Marketplace/README.md) context (restaurants & menus): this
one sells **physical goods** with stock, shipping and tax.

Built with the same Clean Architecture / DDD / Repository / Service-Layer / DI
conventions as the other modules, reusing the Shared Kernel's `Money`, `Slug`
and `Paginated` primitives.

## What it owns

- **Stores** (`Store`) — seller storefronts with an admin **verification**
  lifecycle; only a verified store may publish products and trade.
- **Catalogue** (`Category`, `Product`, `ProductReview`) — categories (general
  or grocery **departments**: produce, pantry, beverages, frozen, household),
  products with **variants**, **pricing**, images, tags, an optional
  **barcode** (scanning architecture-ready), **AI descriptions**, a moderation
  **status** (draft → pending → published/rejected) and **ratings & reviews**.
- **Inventory** (`InventoryItem`, `Warehouse`, `Supplier`) — stock keyed by
  (product, variant SKU), a **low-stock** threshold for alerting, **batch/lot
  tracking with expiry dates** (FEFO deduction), warehouses and suppliers.
- **Cart** (`Cart`) & **Wishlist** (`Wishlist`) — a multi-store cart (a single
  order may span sellers) with an applied coupon, and saved-for-later products.
- **Promotions** (`Promotion`, `Coupon`) — time-boxed product promotions &
  **flash sales**, and order-level **coupon** codes (percentage / fixed /
  free-shipping) with min-spend, expiry and redemption caps.
- **Orders** (`Order`, `OrderLine`, `ReturnRequest`) — checkout with a captured
  money breakdown (**subtotal → discount → tax → shipping → total**), a guarded
  **status** timeline, **returns & refunds**, and service-generated
  **invoices**.
- **Shopping** (`ShoppingList`) — smart, AI-buildable grocery lists.

## Folder structure

```
modules/Commerce/src/
├── Domain/                    # Pure PHP — no framework
│   ├── Enum/                  # ProductStatus/Kind, GroceryDepartment, OrderStatus,
│   │                          #   CouponType, PromotionType, ReturnStatus
│   ├── ValueObject/           # Money(shared), Barcode, ProductVariant, Batch, Address
│   ├── Store/ Catalog/ Inventory/ Cart/ Order/ Promotion/ Shopping/
│   │                          #   aggregates + repository ports
│   ├── Event/                 # ProductPublished, OrderPlaced
│   └── Exception/             # not-found / invalid-state / conflict / not-authorized
├── Application/               # Use cases + ports
│   ├── Port/                  # TaxCalculator, ShippingCalculator, PricingStrategy,
│   │                          #   DiscountEngine, InvoiceGenerator, CommerceAdvisor (AI)
│   ├── Input/ DTO/            # StoreInput/ProductInput/CheckoutInput; PriceBreakdown, Invoice…
│   └── Service/               # Store, Category, Product, Review, Cart, Wishlist, Checkout,
│                              #   Order, Return, Promotion, Coupon, Inventory, ShoppingList,
│                              #   ShoppingAssistant, AdminDashboard, Presenter
├── Infrastructure/            # Adapters
│   ├── Persistence/           # 14 Eloquent models + repositories, 14 migrations
│   ├── Pricing/               # VatTaxCalculator, FlatRateShippingCalculator,
│   │                          #   CataloguePricingStrategy, CouponDiscountEngine
│   ├── Invoice/               # OrderInvoiceGenerator
│   ├── Ai/                    # AiCommerceAdvisor (bridges to the AI contract)
│   ├── Seeder/                # CommerceSeeder (sample Lagos store + grocery catalogue)
│   └── Provider/              # CommerceServiceProvider (composition root)
└── Interface/                 # HTTP (controllers, requests, routes)
```

## Key design decisions

- **A new bounded context, not a `Marketplace` extension.** Food delivery
  (`Marketplace`) and physical-goods commerce share vocabulary but not
  invariants — commerce adds stock, warehouses, tax, shipping, returns and
  invoices. Keeping them separate keeps each aggregate cohesive; all routes are
  under `/commerce` so they never collide.
- **Money is integer minor units** everywhere; every charge is **captured at
  checkout** into the order, so later catalogue/tax/shipping changes never
  rewrite a placed order.
- **One money pipeline**: `subtotal → discount → tax → shipping → total`.
  Discounts, tax and shipping are **ports** (coupon discount engine, VAT
  calculator, flat/threshold shipping) so each can be replaced independently.
- **Dynamic pricing is architecture-ready**: `PricingStrategy` is the seam; the
  default returns the catalogue price after the best active promotion.
- **Guarded state machines**: `OrderStatus` and `ReturnStatus` permit only legal
  transitions; checkout is the single place that commits inventory & money.
- **Multi-store cart** (unlike the single-vendor food cart) — one order can
  contain products from several sellers.
- **Inventory** tracks batches with expiry and deducts **FEFO**
  (first-expiring, first-out); a low-stock query drives alerting.
- **AI shopping** (recommendations, cross/up-sell, assistant, list building)
  goes through the AI module's published `AiAdvisor` **contract** — never
  internals — the same cross-context pattern Marketplace & Nutrition use.

## Authorisation

Store/product management is owner-or-admin checked in the service layer;
customers manage only their own cart/wishlist/orders/returns; store verification,
product approval, inventory, promotions, coupons and return resolution are
`role:admin`.

## Persistence

Fourteen `commerce_*` tables. Other contexts are referenced by ID only
(`owner_user_id`/`customer_user_id` → Identity). Seed sample data:

```
php artisan db:seed --class="EruoFood\Commerce\Infrastructure\Seeder\CommerceSeeder"
```

## Error → HTTP mapping

`COMMERCE_RESOURCE_NOT_FOUND` → 404, `COMMERCE_NOT_AUTHORIZED` → 403,
`COMMERCE_INVALID_STATE` → 422 (illegal transition / empty cart / out of stock /
unverified store / expired coupon), `COMMERCE_CONFLICT` → 409 (duplicate
slug/coupon code / duplicate review or return).

## Testing

- **Unit** — order money breakdown + status lifecycle, coupon eligibility &
  discount maths, inventory FEFO/low-stock/expiry, promotion windows, and cart
  merge/coupon rules.
- **Feature** — store onboarding + verification gating + product approval +
  public search & reviews; the full cart → coupon → checkout (tax/shipping) →
  order → status → return/refund flow; inventory oversell protection; and the
  AI recommendation & shopping-list paths (offline via the AI mock provider).

See [docs/api/commerce-endpoints.md](../../../../docs/api/commerce-endpoints.md)
and [ADR-0007](../../../../docs/adr/0007-commerce-platform.md).
