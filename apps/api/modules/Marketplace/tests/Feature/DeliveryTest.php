<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{owner: string, orderId: string, deliveryId: string} */
function deliveryOrder(object $test): array
{
    ['owner' => $owner, 'itemId' => $itemId] = verifiedVendorWithItem($test, 'delivowner@example.com');
    $customer = mktUserToken($test, 'delivcustomer@example.com');

    $test->withToken($customer)->postJson('/api/v1/cart/items', ['menu_item_id' => $itemId, 'quantity' => 1])->assertCreated();
    $orderId = $test->withToken($customer)->postJson('/api/v1/checkout', [
        'fulfilment' => 'delivery',
        'delivery_address' => [
            'line' => '5 Rd', 'city' => 'Lagos', 'state' => 'Lagos',
            'location' => ['latitude' => 6.50, 'longitude' => 3.38],
        ],
    ])->assertCreated()->json('data.id');

    $deliveryId = $test->withToken($owner)->getJson("/api/v1/orders/{$orderId}/delivery")
        ->assertOk()->json('data.id');

    return ['owner' => $owner, 'orderId' => $orderId, 'deliveryId' => $deliveryId];
}

it('assigns a rider and progresses the delivery with live tracking', function (): void {
    ['owner' => $owner, 'deliveryId' => $deliveryId] = deliveryOrder($this);

    // Onboard a rider.
    $riderToken = mktUserToken($this, 'rider@example.com');
    $riderId = $this->withToken($riderToken)->postJson('/api/v1/riders', [
        'name' => 'Chidi', 'phone' => '+2348000000001', 'vehicle_type' => 'motorbike',
    ])->assertCreated()->json('data.id');

    // Vendor assigns the rider.
    $this->withToken($owner)->postJson("/api/v1/deliveries/{$deliveryId}/assign", ['rider_id' => $riderId])
        ->assertOk()->assertJsonPath('data.status', 'assigned');

    // Rider progresses the delivery.
    $this->withToken($riderToken)->postJson("/api/v1/deliveries/{$deliveryId}/status", ['status' => 'picked_up'])
        ->assertOk()->assertJsonPath('data.status', 'picked_up');

    // Rider streams a tracking point.
    $this->withToken($riderToken)->postJson("/api/v1/deliveries/{$deliveryId}/track", [
        'latitude' => 6.49, 'longitude' => 3.385,
    ])->assertOk()->assertJsonCount(1, 'data.track_points');

    $this->withToken($riderToken)->postJson("/api/v1/deliveries/{$deliveryId}/status", ['status' => 'en_route'])
        ->assertOk()->assertJsonPath('data.status', 'en_route');
    $this->withToken($riderToken)->postJson("/api/v1/deliveries/{$deliveryId}/status", ['status' => 'delivered'])
        ->assertOk()->assertJsonPath('data.status', 'delivered');
});

it('stops a rider from touching a delivery they are not assigned to', function (): void {
    ['deliveryId' => $deliveryId] = deliveryOrder($this);
    $stranger = mktUserToken($this, 'stranger@example.com');
    $this->withToken($stranger)->postJson('/api/v1/riders', [
        'name' => 'Stranger', 'phone' => '+2348000000009', 'vehicle_type' => 'car',
    ])->assertCreated();

    $this->withToken($stranger)->postJson("/api/v1/deliveries/{$deliveryId}/status", ['status' => 'picked_up'])
        ->assertStatus(403);
});
