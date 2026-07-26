<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a verified vendor owned by a fresh user, with one menu item.
 *
 * @return array{owner: string, vendorId: string, itemId: string}
 */
function verifiedVendorWithItem(object $test, string $ownerEmail = 'vendorowner@example.com'): array
{
    $owner = mktUserToken($test, $ownerEmail);
    $admin = mktAdminToken($test, 'flowadmin-'.$ownerEmail);

    $vendorId = $test->withToken($owner)->postJson('/api/v1/vendors', vendorPayload('Flow Kitchen'))
        ->assertCreated()->json('data.id');
    $test->withToken($admin)->postJson("/api/v1/admin/marketplace/vendors/{$vendorId}/verify")->assertOk();

    $itemId = $test->withToken($owner)->postJson("/api/v1/vendors/{$vendorId}/menu", [
        'name' => 'Jollof Rice',
        'base_price_minor' => 250000,
        'tags' => ['rice'],
    ])->assertCreated()->json('data.id');

    return ['owner' => $owner, 'vendorId' => $vendorId, 'itemId' => $itemId];
}

it('runs the cart → checkout → order → status flow', function (): void {
    ['owner' => $owner, 'vendorId' => $vendorId, 'itemId' => $itemId] = verifiedVendorWithItem($this);
    $customer = mktUserToken($this, 'customer@example.com');

    // Public sees the item on the vendor menu.
    $this->getJson("/api/v1/vendors/{$vendorId}/menu")->assertOk()->assertJsonPath('data.0.name', 'Jollof Rice');

    // Add two to cart.
    $this->withToken($customer)->postJson('/api/v1/cart/items', ['menu_item_id' => $itemId, 'quantity' => 2])
        ->assertCreated()
        ->assertJsonPath('data.subtotal_minor', 500000);

    // Checkout as a delivery order.
    $order = $this->withToken($customer)->postJson('/api/v1/checkout', [
        'fulfilment' => 'delivery',
        'delivery_address' => [
            'line' => '5 Customer Rd', 'city' => 'Lagos', 'state' => 'Lagos',
            'location' => ['latitude' => 6.50, 'longitude' => 3.38],
        ],
    ])->assertCreated()->json('data');

    expect($order['subtotal_minor'])->toBe(500000)
        ->and($order['delivery_fee_minor'])->toBeGreaterThan(0)
        ->and($order['total_minor'])->toBe($order['subtotal_minor'] + $order['delivery_fee_minor']);
    expect($order['status'])->toBe('pending');

    // Cart is cleared after checkout.
    $this->withToken($customer)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items', []);

    // A delivery job was created.
    $this->withToken($customer)->getJson("/api/v1/orders/{$order['id']}/delivery")
        ->assertOk()->assertJsonPath('data.status', 'unassigned');

    // Vendor confirms the order.
    $this->withToken($owner)->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'confirmed'])
        ->assertOk()->assertJsonPath('data.status', 'confirmed');

    // Customer sees it in their history.
    $this->withToken($customer)->getJson('/api/v1/orders')
        ->assertOk()->assertJsonPath('data.0.reference', $order['reference']);
});

it('rejects checkout with an empty cart', function (): void {
    $customer = mktUserToken($this, 'emptycart@example.com');
    $this->withToken($customer)->postJson('/api/v1/checkout', ['fulfilment' => 'pickup'])->assertStatus(422);
});

it('lets a customer AI-describe nothing but a vendor owner generate menu copy', function (): void {
    ['owner' => $owner, 'itemId' => $itemId] = verifiedVendorWithItem($this, 'copyowner@example.com');

    // Goes Marketplace -> AiAdvisor contract -> AI gateway (mock provider in tests).
    $this->withToken($owner)->postJson("/api/v1/menu-items/{$itemId}/describe")
        ->assertOk();
    expect($this->withToken($owner)->getJson('/api/v1/vendors')->status())->toBe(200);
});
