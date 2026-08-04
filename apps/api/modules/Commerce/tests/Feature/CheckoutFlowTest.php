<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs cart → coupon → checkout with tax & shipping → order → status', function (): void {
    ['owner' => $owner, 'admin' => $admin, 'productId' => $productId]
        = comVerifiedStoreWithProduct($this, 'checkoutowner@example.com');
    $customer = comUserToken($this, 'checkoutcustomer@example.com');

    // A 10%-off coupon with a min spend.
    $this->withToken($admin)->postJson('/api/v1/commerce/admin/coupons', [
        'code' => 'SAVE10', 'type' => 'percentage', 'value' => 10, 'min_spend_minor' => 100000,
    ])->assertCreated();

    // Add 2 units (950000 each = 1,900,000 subtotal).
    $this->withToken($customer)->postJson('/api/v1/commerce/cart/items', [
        'product_id' => $productId, 'quantity' => 2,
    ])->assertCreated()->assertJsonPath('data.subtotal_minor', 1900000);

    $this->withToken($customer)->postJson('/api/v1/commerce/cart/coupon', ['code' => 'SAVE10'])
        ->assertOk()->assertJsonPath('data.coupon_code', 'SAVE10');

    // Quote: discount 190000; taxable 1,710,000; tax 7.5% = 128250; free shipping over 2,000,000? no.
    $quote = $this->withToken($customer)->getJson('/api/v1/commerce/checkout/quote')->assertOk()->json('data');
    expect($quote['discount_minor'])->toBe(190000)
        ->and($quote['tax_minor'])->toBe(128250)
        ->and($quote['total_minor'])->toBe(1710000 + 128250 + $quote['shipping_minor']);

    // Place the order (shipped).
    $order = $this->withToken($customer)->postJson('/api/v1/commerce/checkout', [
        'pickup' => false,
        'shipping_address' => ['line1' => '5 Customer Rd', 'city' => 'Lagos', 'state' => 'Lagos'],
    ])->assertCreated()->json('data');

    expect($order['subtotal_minor'])->toBe(1900000)
        ->and($order['discount_minor'])->toBe(190000)
        ->and($order['coupon_code'])->toBe('SAVE10')
        ->and($order['status'])->toBe('pending');

    // Cart is cleared.
    $this->withToken($customer)->getJson('/api/v1/commerce/cart')->assertOk()->assertJsonPath('data.items', []);

    // Customer sees it in history.
    $this->withToken($customer)->getJson('/api/v1/commerce/orders')->assertOk()->assertJsonCount(1, 'data');

    // An invoice is generated.
    $this->withToken($customer)->getJson("/api/v1/commerce/orders/{$order['id']}/invoice")
        ->assertOk()->assertJsonPath('data.order_reference', $order['reference']);

    // Seller advances the order through its lifecycle.
    foreach (['paid', 'processing', 'shipped', 'delivered'] as $status) {
        $this->withToken($owner)->postJson("/api/v1/commerce/orders/{$order['id']}/status", ['status' => $status])
            ->assertOk()->assertJsonPath('data.status', $status);
    }

    // Delivered order can be returned; admin refunds it.
    $returnId = $this->withToken($customer)->postJson('/api/v1/commerce/returns', [
        'order_id' => $order['id'], 'reason' => 'Damaged in transit',
    ])->assertCreated()->json('data.id');
    $this->withToken($admin)->postJson("/api/v1/commerce/admin/returns/{$returnId}/resolve", ['status' => 'approved'])
        ->assertOk();
    $this->withToken($admin)->postJson("/api/v1/commerce/admin/returns/{$returnId}/resolve", ['status' => 'refunded'])
        ->assertOk()->assertJsonPath('data.status', 'refunded');

    // The order is now marked returned.
    $this->withToken($customer)->getJson("/api/v1/commerce/orders/{$order['id']}")
        ->assertOk()->assertJsonPath('data.status', 'returned');
});

it('deducts tracked inventory at checkout and blocks oversell', function (): void {
    ['admin' => $admin, 'productId' => $productId] = comVerifiedStoreWithProduct($this, 'stockowner@example.com');
    $customer = comUserToken($this, 'stockcustomer@example.com');

    // Stock only 1 unit.
    $this->withToken($admin)->postJson('/api/v1/commerce/admin/inventory/receive', [
        'product_id' => $productId, 'quantity' => 1,
    ])->assertCreated();

    $this->withToken($customer)->postJson('/api/v1/commerce/cart/items', [
        'product_id' => $productId, 'quantity' => 2,
    ])->assertCreated();

    // Not enough stock → 422.
    $this->withToken($customer)->postJson('/api/v1/commerce/checkout', ['pickup' => true])
        ->assertStatus(422);
});

it('serves AI recommendations and the shopping assistant offline', function (): void {
    comVerifiedStoreWithProduct($this, 'aiowner@example.com');
    $customer = comUserToken($this, 'aicustomer@example.com');

    // Recommendations are public and run through the AI mock provider.
    $this->getJson('/api/v1/commerce/recommendations')->assertOk()->assertJsonStructure(['data' => ['blurb', 'products']]);

    // The assistant builds a shopping list from a prompt.
    $this->withToken($customer)->postJson('/api/v1/commerce/shopping-lists/build', [
        'name' => 'Weekend', 'prompt' => 'ingredients for jollof rice for 6',
    ])->assertCreated()->assertJsonPath('data.name', 'Weekend');
});
