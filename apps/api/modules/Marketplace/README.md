# Marketplace Module (`EruoFood\Marketplace`)

The **Restaurant, Vendor & Food Business Platform** bounded context — a food
marketplace: vendors and their menus, a shopping cart, checkout and orders,
delivery with riders and live tracking, and search & discovery. Built with the
same Clean Architecture / DDD / Repository / Service-Layer / DI conventions as
the other modules, and reusing the Shared Kernel's `Money` value object.

## What it owns

- **Vendors** (`Vendor`) — restaurants, market vendors, home & cloud kitchens:
  registration, admin **verification** lifecycle, profiles, **branches**,
  **business hours**, **contact**, **geolocation**, **delivery zones**,
  categories, and **ratings & reviews**. Only verified vendors trade.
- **Menus** (`MenuCategory`, `MenuItem`) — categories, items with **variants**,
  **pricing** (base + variant delta + **promotions**), **availability**,
  **images**, **AI-generated descriptions**, optional **inventory** and an
  optional soft link to the Nutrition module for **nutritional info**, plus
  **featured** items.
- **Cart** (`Cart`) — a single-vendor shopping cart per user.
- **Orders** (`Order`) — checkout, priced line snapshots, delivery **or** pickup,
  **scheduled** orders, a guarded **status** timeline, tracking and history.
- **Delivery** (`Delivery`) & **Riders** (`Rider`) — delivery jobs, rider
  onboarding, assignment, **delivery fees**, **live tracking** breadcrumbs, and
  **route optimisation** (a port with a nearest-neighbour default —
  architecture-ready).
- **Search & discovery** — vendors by name/type/category/**geolocation**, and
  menu-item search.

## Folder structure

```
modules/Marketplace/src/
├── Domain/                    # Pure PHP — no framework
│   ├── Enum/                  # VendorType/Status, OrderStatus, FulfilmentType, DeliveryStatus, RiderStatus
│   ├── ValueObject/           # GeoLocation, Address, BusinessHours, DeliveryZone, Branch,
│   │                          #   ContactInfo, MenuVariant, Promotion
│   ├── Vendor/                # Vendor aggregate, VendorReview, search criteria + repos
│   ├── Menu/                  # MenuCategory, MenuItem + repos
│   ├── Cart/                  # Cart aggregate, CartItem + repo
│   ├── Order/                 # Order aggregate, OrderLine + repo
│   ├── Delivery/              # Delivery aggregate + repo
│   ├── Rider/                 # Rider aggregate + repo
│   ├── Event/                 # VendorVerified, OrderPlaced
│   └── Exception/             # not-found / not-authorized / invalid-state / conflict
├── Application/               # Use cases + ports
│   ├── Port/                  # MenuDescriber (AI), DeliveryFeeCalculator, RouteOptimizer
│   ├── Input/                 # VendorInput, MenuItemInput, CheckoutInput
│   ├── DTO/                   # DeliveryQuote, SalesSummary
│   └── Service/               # Vendor, Menu, Cart, Checkout, Order, Delivery, Rider,
│                              #   VendorReview, VendorDashboard, Search, Presenter
├── Infrastructure/            # Adapters
│   ├── Persistence/           # Eloquent models, repositories, 8 migrations
│   ├── Ai/                    # AiMenuDescriber (bridges to the AI contract)
│   ├── Delivery/              # ZoneDeliveryFeeCalculator, NearestFirstRouteOptimizer
│   ├── Seeder/                # MarketplaceSeeder (sample Lagos vendors + menu)
│   └── Provider/              # MarketplaceServiceProvider (composition root)
└── Interface/                 # HTTP (controllers, requests, routes)
```

## Key design decisions

- **One unifying `Vendor` aggregate** models restaurants and vendors (a `type`
  distinguishes them) rather than duplicating near-identical concepts.
- **Money is integer minor units** everywhere (reusing `Shared\Money`); prices
  are **captured at checkout** into order lines, so later menu edits never alter
  a placed order.
- **Guarded state machines**: `OrderStatus` and the delivery status only permit
  legal forward transitions (or an early cancel); the aggregate rejects the rest.
- **Single-vendor cart**: adding an item from a different vendor requires
  clearing the cart, matching real food-delivery UX.
- **AI menu descriptions** go through the AI module's published
  `EruoFood\Ai\Contracts\AiAdvisor` contract (never internals) — the same clean
  cross-context pattern the Nutrition module uses.
- **Delivery fees & routing are ports.** `DeliveryFeeCalculator` (free-over
  threshold → vendor zone → distance-based, haversine) and `RouteOptimizer`
  (nearest-neighbour) can be swapped for external engines without touching
  callers.
- **Geolocation search** uses a lat/lng bounding-box pre-filter plus a
  monotonic distance sort — portable SQL, no PostGIS dependency.

## Authorisation

Every mutation is owner-or-admin checked in the service layer: vendor management,
menu edits and order-status changes require the vendor's owner (or admin);
customers manage only their own cart/orders; riders act only on deliveries
assigned to them. Admin verification is `role:admin`.

## Persistence

Eight `marketplace_*` tables. Other contexts are referenced by ID only (soft
refs: `owner_user_id`/`customer_user_id` → Identity, `nutrition_item_id` →
Nutrition). Seed sample data:

```
php artisan db:seed --class="EruoFood\Marketplace\Infrastructure\Seeder\MarketplaceSeeder"
```

## Error → HTTP mapping

`MARKETPLACE_RESOURCE_NOT_FOUND` → 404, `MARKETPLACE_NOT_AUTHORIZED` → 403,
`MARKETPLACE_INVALID_STATE` → 422 (illegal transition / empty cart / out of
stock / vendor cannot trade), `MARKETPLACE_CONFLICT` → 409 (duplicate slug).

## Testing

- **Unit** — order totals + status transitions, cart merge/single-vendor rules,
  menu pricing (variant + promotion) + inventory, geolocation distance, and the
  delivery-fee model.
- **Feature** — vendor onboarding + admin verification + geo search, the full
  cart → checkout → order → vendor-status flow, and rider assignment + live
  tracking (including the AI menu-describe path through the AI contract).

See [docs/api/marketplace-endpoints.md](../../../../docs/api/marketplace-endpoints.md)
and [ADR-0006](../../../../docs/adr/0006-marketplace-platform.md).
