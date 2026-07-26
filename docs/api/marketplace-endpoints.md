# Restaurant, Vendor & Food Business — API Endpoints

Base URL: `/api/v1`. Discovery (browse/search vendors & menus) is public;
everything tied to a user (cart, checkout, orders, vendor/rider management)
needs a bearer token; vendor verification needs the `admin` role. Money is in
**integer minor units** (kobo). Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Discovery (public)

| Method & Path | Purpose |
|---|---|
| `GET /vendors` | Browse/search verified vendors. Filters: `q`, `type`, `category`, `lat`+`lng`+`radius_km`, `featured`, `sort` (`rating`\|`nearest`\|`name`\|`recent`). |
| `GET /vendors/{slug}` | Vendor storefront detail. |
| `GET /vendors/{vendorId}/menu` | A vendor's menu items. |
| `GET /vendors/{vendorId}/menu/categories` | A vendor's menu categories. |
| `GET /vendors/{id}/reviews` | Paginated vendor reviews. |
| `GET /search/vendors` | Vendor search (same filters as `/vendors`; defaults to `nearest` when `lat`/`lng` given). |
| `GET /search/menu-items` | Search available items: `q`, `vendor_id`, `featured`. |

## Vendor onboarding & management (owner)

| Method & Path | Purpose |
|---|---|
| `POST /vendors` | Register a vendor (starts **pending** unless verification is disabled). |
| `GET /me/vendors` | The caller's vendors. |
| `PUT /vendors/{id}` | Update the profile. |
| `PUT /vendors/{id}/hours` | Set business hours (`hours` map: weekday → `{open,close}` HH:MM). |
| `PUT /vendors/{id}/delivery-zones` | Set delivery zones (`name`, `fee_minor`, `radius_km`). |
| `PUT /vendors/{id}/branches` | Set branches. |
| `PUT /vendors/{id}/images` | Set storefront images. |
| `GET /vendors/{id}/dashboard` | Sales summary (orders, revenue). |
| `GET /vendors/{vendorId}/orders` | The vendor's orders (`status` filter). |
| `POST /vendors/{id}/reviews` | Rate & review a vendor (auth; one per user). |

## Menu management (owner)

| Method & Path | Purpose |
|---|---|
| `POST /vendors/{vendorId}/menu` | Create a menu item. |
| `POST /vendors/{vendorId}/menu/categories` | Create a category. |
| `PUT /menu-items/{itemId}` · `DELETE /menu-items/{itemId}` | Update / delete an item. |
| `PATCH /menu-items/{itemId}/availability` | Toggle availability (`available`). |
| `PATCH /menu-items/{itemId}/featured` | Toggle featured. |
| `PUT /menu-items/{itemId}/promotion` | Set/clear a promotion (`type`: percentage\|fixed, `value`). |
| `PATCH /menu-items/{itemId}/stock` | Restock (`stock`). |
| `POST /menu-items/{itemId}/describe` | AI-generate the item description (via the AI contract). |
| `DELETE /menu-categories/{categoryId}` | Delete a category. |

## Cart, checkout & orders (customer)

| Method & Path | Purpose |
|---|---|
| `GET /cart` · `DELETE /cart` | View / clear the cart. |
| `POST /cart/items` | Add an item (`menu_item_id`, `quantity`, `variant_name?`). |
| `PUT /cart/items` · `DELETE /cart/items` | Change quantity / remove an item. |
| `POST /checkout` | Place the order (`fulfilment`: delivery\|pickup, `delivery_address?`, `scheduled_for?`, `note?`). |
| `GET /orders` · `GET /orders/{id}` | History / detail + tracking. |
| `POST /orders/{id}/cancel` | Cancel (customer, owning vendor, or admin; not once delivered). |
| `POST /orders/{id}/status` | Advance status (owning vendor): confirmed → preparing → ready → dispatched → delivered. |

## Delivery & riders

| Method & Path | Purpose |
|---|---|
| `GET /orders/{orderId}/delivery` | The delivery for an order. |
| `POST /deliveries/{id}/assign` | Assign a rider (owning vendor) — `rider_id`. |
| `POST /deliveries/{id}/status` | Progress delivery (assigned rider): picked_up → en_route → delivered / failed. |
| `POST /deliveries/{id}/track` | Append a live-tracking point (`latitude`, `longitude`). |
| `POST /riders` | Onboard as a rider (`name`, `phone`, `vehicle_type`). |
| `GET /riders/me` | The caller's rider profile. |
| `PATCH /riders/me/status` | Set availability (`available`\|`busy`\|`offline`). |
| `POST /riders/me/location` | Update live location. |

## Admin (role: admin)

| Method & Path | Purpose |
|---|---|
| `POST /admin/marketplace/vendors/{id}/verify` | Verify a vendor (may now trade). |
| `POST /admin/marketplace/vendors/{id}/reject` · `/suspend` | Reject / suspend. |
| `POST /admin/marketplace/vendors/{id}/feature` | Toggle featured (`featured`). |

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `MARKETPLACE_RESOURCE_NOT_FOUND` | 404 | Vendor/item/order/delivery/rider missing. |
| `MARKETPLACE_NOT_AUTHORIZED` | 403 | Not the owner / assigned rider. |
| `MARKETPLACE_INVALID_STATE` | 422 | Illegal status move, empty cart, out of stock, vendor can't trade, mixed-vendor cart. |
| `MARKETPLACE_CONFLICT` | 409 | Duplicate vendor slug / already a rider. |
