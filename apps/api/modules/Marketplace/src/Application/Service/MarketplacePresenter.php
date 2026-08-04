<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Cart\Cart;
use EruoFood\Marketplace\Domain\Cart\CartItem;
use EruoFood\Marketplace\Domain\Delivery\Delivery;
use EruoFood\Marketplace\Domain\Menu\MenuCategory;
use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Domain\Order\OrderLine;
use EruoFood\Marketplace\Domain\Rider\Rider;
use EruoFood\Marketplace\Domain\ValueObject\Branch;
use EruoFood\Marketplace\Domain\ValueObject\DeliveryZone;
use EruoFood\Marketplace\Domain\ValueObject\MenuVariant;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorReview;

/** Maps Marketplace aggregates to API-shaped arrays. */
final readonly class MarketplacePresenter
{
    /** @return array<string, mixed> */
    public function vendorSummary(Vendor $v): array
    {
        return [
            'id' => $v->id(),
            'name' => $v->name(),
            'slug' => (string) $v->slug(),
            'type' => $v->type()->value,
            'category' => $v->category(),
            'status' => $v->status()->value,
            'rating_average' => $v->ratingAverage(),
            'rating_count' => $v->ratingCount(),
            'featured' => $v->isFeatured(),
            'location' => $v->location()?->toArray(),
            'primary_image' => $v->images()[0] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function vendor(Vendor $v): array
    {
        return array_merge($this->vendorSummary($v), [
            'owner_user_id' => $v->ownerUserId(),
            'description' => $v->description(),
            'contact' => $v->contact()->toArray(),
            'address' => $v->address()->toArray(),
            'branches' => array_map(static fn (Branch $b): array => $b->toArray(), $v->branches()),
            'business_hours' => $v->businessHours()->toArray(),
            'delivery_zones' => array_map(static fn (DeliveryZone $z): array => $z->toArray(), $v->deliveryZones()),
            'images' => $v->images(),
            'created_at' => $v->createdAt()->format(DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    public function category(MenuCategory $c): array
    {
        return ['id' => $c->id(), 'vendor_id' => $c->vendorId(), 'name' => $c->name(), 'sort_order' => $c->sortOrder()];
    }

    /** @return array<string, mixed> */
    public function menuItem(MenuItem $i): array
    {
        return [
            'id' => $i->id(),
            'vendor_id' => $i->vendorId(),
            'category_id' => $i->categoryId(),
            'name' => $i->name(),
            'description' => $i->description(),
            'description_ai_generated' => $i->descriptionIsAiGenerated(),
            'base_price_minor' => $i->basePrice()->minorUnits,
            'currency' => $i->basePrice()->currency,
            'variants' => array_map(static fn (MenuVariant $x): array => $x->toArray(), $i->variants()),
            'available' => $i->isAvailable(),
            'orderable' => $i->isOrderable(),
            'images' => $i->images(),
            'tags' => $i->tags(),
            'featured' => $i->isFeatured(),
            'promotion' => $i->promotion()?->toArray(),
            'tracks_inventory' => $i->tracksInventory(),
            'stock' => $i->tracksInventory() ? $i->stock() : null,
            'calories' => $i->calories(),
            'nutrition_item_id' => $i->nutritionItemId(),
        ];
    }

    /** @return array<string, mixed> */
    public function cart(Cart $c): array
    {
        return [
            'vendor_id' => $c->vendorId(),
            'currency' => $c->currency(),
            'items' => array_map(static fn (CartItem $i): array => $i->toArray(), $c->items()),
            'subtotal_minor' => $c->subtotal()->minorUnits,
        ];
    }

    /** @return array<string, mixed> */
    public function orderSummary(Order $o): array
    {
        return [
            'id' => $o->id(),
            'reference' => $o->reference(),
            'vendor_id' => $o->vendorId(),
            'status' => $o->status()->value,
            'fulfilment' => $o->fulfilment()->value,
            'total_minor' => $o->total()->minorUnits,
            'currency' => $o->total()->currency,
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
            'delivery_fee_minor' => $o->deliveryFee()->minorUnits,
            'delivery_address' => $o->deliveryAddress()?->toArray(),
            'scheduled_for' => $o->scheduledFor()?->format(DATE_ATOM),
            'note' => $o->note(),
            'status_history' => $o->statusHistory(),
        ]);
    }

    /** @return array<string, mixed> */
    public function delivery(Delivery $d): array
    {
        return [
            'id' => $d->id(),
            'order_id' => $d->orderId(),
            'rider_id' => $d->riderId(),
            'status' => $d->status()->value,
            'fee_minor' => $d->fee()->minorUnits,
            'zone_name' => $d->zoneName(),
            'pickup' => $d->pickup()?->toArray(),
            'dropoff' => $d->dropoff()?->toArray(),
            'track_points' => $d->trackPoints(),
            'assigned_at' => $d->assignedAt()?->format(DATE_ATOM),
            'delivered_at' => $d->deliveredAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function rider(Rider $r): array
    {
        return [
            'id' => $r->id(),
            'name' => $r->name(),
            'phone' => $r->phone(),
            'vehicle_type' => $r->vehicleType(),
            'status' => $r->status()->value,
            'location' => $r->location()?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function review(VendorReview $r): array
    {
        return [
            'id' => $r->id(),
            'vendor_id' => $r->vendorId(),
            'user_id' => $r->userId(),
            'rating' => $r->rating(),
            'comment' => $r->comment(),
            'created_at' => $r->createdAt()->format(DATE_ATOM),
        ];
    }
}
