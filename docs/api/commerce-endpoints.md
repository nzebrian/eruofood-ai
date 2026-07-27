# Marketplace, Grocery & Commerce — API Endpoints

Base URL: `/api/v1`. All commerce paths are under **`/commerce`** (so they never
collide with the food-delivery Marketplace module). Discovery (browse/search
products & stores, promotions, recommendations) is public; anything tied to a
user (cart, wishlist, checkout, orders, returns, shopping lists) needs a bearer
token; moderation, inventory, promotions, coupons and returns resolution need the
`admin` role. Money is in **integer minor units** (kobo). Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Discovery (public)

| Method & Path | Purpose |
|---|---|
| `GET /commerce/products` | Search the catalogue. Filters: `q`, `kind` (`grocery`\|`general`), `department`, `store_id`, `category_id`, `min_price`, `max_price`, `featured`, `sort` (`relevance`\|`price_asc`\|`price_desc`\|`rating`\|`newest`). |
| `GET /commerce/products/{slug}` | Product detail + related items. |
| `GET /commerce/products/barcode/{barcode}` | Look up a published product by barcode. |
| `GET /commerce/products/{id}/reviews` | Paginated product reviews. |
| `GET /commerce/categories` | Categories (filter `kind`, `department`). |
| `GET /commerce/stores` · `GET /commerce/stores/{slug}` | Browse verified stores / storefront detail. |
| `GET /commerce/stores/{storeId}/products` | A store's published products. |
| `GET /commerce/promotions` · `GET /commerce/promotions/flash-sales` | Active promotions / flash sales. |
| `GET /commerce/recommendations` | AI product recommendations. |
| `GET /commerce/products/{productId}/cross-sell` · `/up-sell` | AI cross-sell / up-sell suggestions. |

## Stores & products (seller)

| Method & Path | Purpose |
|---|---|
| `GET /commerce/me/stores` | The caller's stores. |
| `POST /commerce/stores` · `PUT /commerce/stores/{id}` | Register / update a store (starts **unverified**). |
| `GET /commerce/stores/{storeId}/manage/products` | The store's products (any status). |
| `POST /commerce/stores/{storeId}/products` | Create a product (needs a **verified** store). |
| `PUT /commerce/products/{productId}` · `DELETE /commerce/products/{productId}` | Update / delete a product. |
| `POST /commerce/products/{productId}/submit` | Submit a product for admin approval. |
| `POST /commerce/products/{productId}/describe` | AI-generate the product description. |
| `POST /commerce/products/{productId}/reviews` | Rate & review a product (one per user). |
| `GET /commerce/stores/{storeId}/orders` | The store's orders. |

## Cart, wishlist & checkout (customer)

| Method & Path | Purpose |
|---|---|
| `GET /commerce/cart` · `DELETE /commerce/cart` | View / clear the cart. |
| `POST /commerce/cart/items` | Add an item (`product_id`, `quantity`, `variant_sku?`). |
| `PUT /commerce/cart/items` · `DELETE /commerce/cart/items` | Change quantity / remove an item. |
| `POST /commerce/cart/coupon` | Apply/clear a coupon (`code`). |
| `GET /commerce/wishlist` · `POST /commerce/wishlist` · `DELETE /commerce/wishlist/{productId}` | Manage the wishlist. |
| `GET /commerce/checkout/quote` | Price the cart (subtotal → discount → tax → shipping → total); `pickup` toggles shipping. |
| `POST /commerce/checkout` | Place the order (`pickup`, `shipping_address?`, `scheduled_for?`, `note?`). |

## Orders & returns (customer / seller)

| Method & Path | Purpose |
|---|---|
| `GET /commerce/orders` · `GET /commerce/orders/{id}` | History / detail. |
| `GET /commerce/orders/{id}/invoice` | The generated invoice. |
| `POST /commerce/orders/{id}/cancel` | Cancel (customer/admin; before shipping). |
| `POST /commerce/orders/{id}/status` | Advance status (seller/admin): paid → processing → shipped → delivered. |
| `GET /commerce/returns` · `POST /commerce/returns` | Return history / request a return (delivered orders). |

## Smart shopping (customer)

| Method & Path | Purpose |
|---|---|
| `GET /commerce/shopping-lists` · `POST /commerce/shopping-lists` | List / create a shopping list. |
| `POST /commerce/shopping-lists/build` | AI-build a list from a prompt (`name`, `prompt`). |
| `POST /commerce/shopping-lists/{id}/lines` · `PATCH …/lines` · `DELETE …/lines/{index}` | Add / tick-off / remove lines. |
| `DELETE /commerce/shopping-lists/{id}` | Delete a list. |
| `POST /commerce/assistant/ask` | Ask the AI shopping assistant (`question`). |

## Admin (role: admin)

| Method & Path | Purpose |
|---|---|
| `POST /commerce/admin/stores/{id}/verify` · `/suspend` | Verify / suspend a store. |
| `POST /commerce/admin/categories` · `DELETE …/{id}` | Manage categories. |
| `GET /commerce/admin/products/queue` | Product approval queue. |
| `POST /commerce/admin/products/{id}/approve` · `/reject` · `/feature` | Moderate products. |
| `POST /commerce/admin/promotions` · `DELETE …/{id}` | Manage promotions & flash sales. |
| `GET /commerce/admin/coupons` · `POST …` · `POST …/{id}/deactivate` | Manage coupons. |
| `GET /commerce/admin/returns` · `POST …/{id}/resolve` | List / resolve returns (approve\|reject\|refund). |
| `POST /commerce/admin/inventory/receive` | Receive stock (optional batch + expiry). |
| `POST /commerce/admin/inventory/{id}/adjust` | Adjust stock by a delta. |
| `GET /commerce/admin/inventory/low-stock` | Low-stock alerting list. |
| `GET /commerce/admin/inventory/products/{productId}` | Stock records for a product. |
| `GET /commerce/admin/warehouses` · `POST …` · `GET /commerce/admin/suppliers` · `POST …` | Warehouses & suppliers. |
| `GET /commerce/admin/orders` | Monitor all orders. |
| `GET /commerce/admin/stores/{storeId}/sales` | Per-store sales report. |

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `COMMERCE_RESOURCE_NOT_FOUND` | 404 | Store/product/order/coupon/return… missing. |
| `COMMERCE_NOT_AUTHORIZED` | 403 | Not the owner / not a seller in the order. |
| `COMMERCE_INVALID_STATE` | 422 | Illegal status move, empty cart, out of stock, unverified store, expired/exhausted coupon. |
| `COMMERCE_CONFLICT` | 409 | Duplicate store/product slug, coupon code, review or return. |
