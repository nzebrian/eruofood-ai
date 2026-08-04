<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Cart\CartItem;
use EruoFood\Commerce\Domain\Catalog\Category;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductReview;
use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\Inventory\Supplier;
use EruoFood\Commerce\Domain\Inventory\Warehouse;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Domain\Order\OrderLine;
use EruoFood\Commerce\Domain\Order\ReturnRequest;
use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Commerce\Domain\Promotion\Promotion;
use EruoFood\Commerce\Domain\Shopping\ShoppingList;
use EruoFood\Commerce\Domain\Shopping\Wishlist;
use EruoFood\Commerce\Domain\Store\Store;
use EruoFood\Commerce\Domain\ValueObject\Batch;
use EruoFood\Commerce\Domain\ValueObject\ProductVariant;

/** Maps Commerce aggregates to API-shaped arrays. */
final readonly class CommercePresenter
{
    /** @return array<string, mixed> */
    public function storeSummary(Store $s): array
    {
        return [
            'id' => $s->id(),
            'name' => $s->name(),
            'slug' => (string) $s->slug(),
            'verified' => $s->isVerified(),
            'logo' => $s->logo(),
            'rating_average' => $s->ratingAverage(),
            'rating_count' => $s->ratingCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function store(Store $s): array
    {
        return array_merge($this->storeSummary($s), [
            'owner_user_id' => $s->ownerUserId(),
            'description' => $s->description(),
            'address' => $s->address()?->toArray(),
            'support_email' => $s->supportEmail(),
            'support_phone' => $s->supportPhone(),
            'created_at' => $s->createdAt()->format(DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    public function category(Category $c): array
    {
        return [
            'id' => $c->id(),
            'name' => $c->name(),
            'slug' => (string) $c->slug(),
            'kind' => $c->kind()->value,
            'department' => $c->department()?->value,
            'parent_id' => $c->parentId(),
            'sort_order' => $c->sortOrder(),
        ];
    }

    /** @return array<string, mixed> */
    public function productSummary(Product $p): array
    {
        return [
            'id' => $p->id(),
            'store_id' => $p->storeId(),
            'name' => $p->name(),
            'slug' => (string) $p->slug(),
            'kind' => $p->kind()->value,
            'department' => $p->department()?->value,
            'base_price_minor' => $p->basePrice()->minorUnits,
            'currency' => $p->basePrice()->currency,
            'primary_image' => $p->images()[0] ?? null,
            'status' => $p->status()->value,
            'featured' => $p->isFeatured(),
            'rating_average' => $p->ratingAverage(),
            'rating_count' => $p->ratingCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function product(Product $p): array
    {
        return array_merge($this->productSummary($p), [
            'category_id' => $p->categoryId(),
            'description' => $p->description(),
            'description_ai_generated' => $p->descriptionIsAiGenerated(),
            'brand' => $p->brand(),
            'barcode' => $p->barcode() !== null ? (string) $p->barcode() : null,
            'variants' => array_map(static fn (ProductVariant $v): array => $v->toArray(), $p->variants()),
            'images' => $p->images(),
            'tags' => $p->tags(),
        ]);
    }

    /** @return array<string, mixed> */
    public function review(ProductReview $r): array
    {
        return [
            'id' => $r->id(),
            'product_id' => $r->productId(),
            'user_id' => $r->userId(),
            'rating' => $r->rating(),
            'comment' => $r->comment(),
            'created_at' => $r->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function cart(Cart $c): array
    {
        return [
            'currency' => $c->currency(),
            'coupon_code' => $c->couponCode(),
            'items' => array_map(static fn (CartItem $i): array => $i->toArray(), $c->items()),
            'item_count' => $c->itemCount(),
            'subtotal_minor' => $c->subtotal()->minorUnits,
        ];
    }

    /** @return array<string, mixed> */
    public function wishlist(Wishlist $w): array
    {
        return ['user_id' => $w->userId(), 'product_ids' => $w->productIds()];
    }

    /** @return array<string, mixed> */
    public function orderSummary(Order $o): array
    {
        return [
            'id' => $o->id(),
            'reference' => $o->reference(),
            'status' => $o->status()->value,
            'total_minor' => $o->total()->minorUnits,
            'currency' => $o->total()->currency,
            'pickup' => $o->isPickup(),
            'placed_at' => $o->placedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function order(Order $o): array
    {
        return array_merge($this->orderSummary($o), [
            'customer_user_id' => $o->customerUserId(),
            'lines' => array_map(static fn (OrderLine $l): array => $l->toArray(), $o->lines()),
            'subtotal_minor' => $o->subtotal()->minorUnits,
            'discount_minor' => $o->discount()->minorUnits,
            'tax_minor' => $o->tax()->minorUnits,
            'shipping_minor' => $o->shipping()->minorUnits,
            'coupon_code' => $o->couponCode(),
            'shipping_address' => $o->shippingAddress()?->toArray(),
            'scheduled_for' => $o->scheduledFor()?->format(DATE_ATOM),
            'note' => $o->note(),
            'status_history' => $o->statusHistory(),
        ]);
    }

    /** @return array<string, mixed> */
    public function returnRequest(ReturnRequest $r): array
    {
        return [
            'id' => $r->id(),
            'order_id' => $r->orderId(),
            'customer_user_id' => $r->customerUserId(),
            'reason' => $r->reason(),
            'refund_minor' => $r->refundAmount()->minorUnits,
            'currency' => $r->refundAmount()->currency,
            'status' => $r->status()->value,
            'resolution_note' => $r->resolutionNote(),
            'requested_at' => $r->requestedAt()->format(DATE_ATOM),
            'resolved_at' => $r->resolvedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function promotion(Promotion $p): array
    {
        return [
            'id' => $p->id(),
            'store_id' => $p->storeId(),
            'name' => $p->name(),
            'type' => $p->type()->value,
            'value' => $p->value(),
            'product_ids' => $p->productIds(),
            'starts_at' => $p->startsAt()?->format(DATE_ATOM),
            'ends_at' => $p->endsAt()?->format(DATE_ATOM),
            'flash_sale' => $p->isFlashSale(),
        ];
    }

    /** @return array<string, mixed> */
    public function coupon(Coupon $c): array
    {
        return [
            'id' => $c->id(),
            'code' => $c->code(),
            'type' => $c->type()->value,
            'value' => $c->value(),
            'min_spend_minor' => $c->minSpendMinor(),
            'max_redemptions' => $c->maxRedemptions(),
            'times_redeemed' => $c->timesRedeemed(),
            'expires_at' => $c->expiresAt()?->format(DATE_ATOM),
            'active' => $c->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    public function inventoryItem(InventoryItem $i): array
    {
        return [
            'id' => $i->id(),
            'product_id' => $i->productId(),
            'variant_sku' => $i->variantSku(),
            'warehouse_id' => $i->warehouseId(),
            'supplier_id' => $i->supplierId(),
            'quantity' => $i->quantity(),
            'low_stock_threshold' => $i->lowStockThreshold(),
            'low_stock' => $i->isLowStock(),
            'nearest_expiry' => $i->nearestExpiry()?->format(DATE_ATOM),
            'batches' => array_map(static fn (Batch $b): array => $b->toArray(), $i->batches()),
        ];
    }

    /** @return array<string, mixed> */
    public function warehouse(Warehouse $w): array
    {
        return [
            'id' => $w->id(),
            'name' => $w->name(),
            'code' => $w->code(),
            'address' => $w->address()?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function supplier(Supplier $s): array
    {
        return [
            'id' => $s->id(),
            'name' => $s->name(),
            'contact_name' => $s->contactName(),
            'email' => $s->email(),
            'phone' => $s->phone(),
        ];
    }

    /** @return array<string, mixed> */
    public function shoppingList(ShoppingList $l): array
    {
        return [
            'id' => $l->id(),
            'user_id' => $l->userId(),
            'name' => $l->name(),
            'lines' => $l->lines(),
        ];
    }
}
