<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Marketplace\Application\Input\CheckoutInput;
use EruoFood\Marketplace\Application\Service\CheckoutService;
use EruoFood\Marketplace\Domain\Delivery\DeliveryRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\DeliveryModel;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\MenuItemModel;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\OrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M23 — checkout is one transaction.
 *
 * Before M23 each step (stock deduction, order, delivery, cart clear) committed
 * on its own, so a failure part-way left stock deducted against an order that
 * was never created. These tests fail the run at a chosen point and assert the
 * database is untouched.
 */

/**
 * Self-contained fixture: a verified vendor with one stocked menu item.
 *
 * Deliberately not reusing OrderFlowTest's helper — a test file that only works
 * when another file happens to be loaded is a trap for whoever next runs a
 * single file.
 *
 * @return array{owner: string, ownerEmail: string, vendorId: string, itemId: string}
 */
function atomicVendorFixture(object $test, string $suffix): array
{
    Mail::fake();

    $register = static function (string $email) use ($test): array {
        return $test->postJson('/api/v1/auth/register', [
            'name' => 'Atomic User',
            'email' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated()->json('data');
    };

    $ownerEmail = "owner-{$suffix}@example.com";
    $owner = $register($ownerEmail)['tokens']['access_token'];

    $adminEmail = "admin-{$suffix}@example.com";
    $register($adminEmail);
    UserModel::query()->where('email', $adminEmail)->update(['roles' => ['admin']]);
    $admin = $test->postJson('/api/v1/auth/login', ['email' => $adminEmail, 'password' => 'Password123'])
        ->json('data.tokens.access_token');

    $vendorId = $test->withToken($owner)->postJson('/api/v1/vendors', [
        'name' => "Atomic Kitchen {$suffix}",
        'type' => 'restaurant',
        'category' => 'african',
        'contact' => ['phone' => '+2348000000000', 'email' => 'hi@example.com'],
        'address' => [
            'line' => '1 Demo Street', 'city' => 'Lagos', 'state' => 'Lagos',
            'location' => ['latitude' => 6.4550, 'longitude' => 3.3841],
        ],
    ])->assertCreated()->json('data.id');

    $test->withToken($admin)->postJson("/api/v1/admin/marketplace/vendors/{$vendorId}/verify")->assertOk();

    $itemId = $test->withToken($owner)->postJson("/api/v1/vendors/{$vendorId}/menu", [
        'name' => 'Jollof Rice',
        'base_price_minor' => 250000,
        'tags' => ['rice'],
    ])->assertCreated()->json('data.id');

    return ['owner' => $owner, 'ownerEmail' => $ownerEmail, 'vendorId' => $vendorId, 'itemId' => $itemId];
}

/** Register a customer and return [token, id]. */
function atomicCustomer(object $test, string $email): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Atomic Customer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return [$data['tokens']['access_token'], $data['user']['id']];
}

/**
 * Fail the *last* write in the checkout sequence.
 *
 * The delivery record is created after the order and after the stock deduction,
 * so this is the failure point that actually distinguishes the fix: under the
 * old code the order and the deducted stock were already committed by their own
 * transactions and would survive. Failing an earlier step would prove nothing.
 *
 * Only the methods checkout calls are stubbed — an unexpected call should fail
 * loudly rather than be absorbed by a hand-written pass-through.
 */
function failingDeliveryRepository(): void
{
    $real = app(DeliveryRepository::class);

    $failing = Mockery::mock(DeliveryRepository::class);
    $failing->shouldReceive('nextIdentity')->andReturnUsing(static fn (): string => $real->nextIdentity());
    $failing->shouldReceive('save')->andThrow(new RuntimeException('storage failed while writing the delivery'));

    app()->instance(DeliveryRepository::class, $failing);
}

it('rolls back the order and the stock deduction when a later step fails', function (): void {
    ['owner' => $owner, 'itemId' => $itemId] = atomicVendorFixture($this, 'rollback');

    // Track stock on the item so the checkout has something to deduct.
    $this->withToken($owner)->patchJson("/api/v1/menu-items/{$itemId}/stock", ['stock' => 10])->assertOk();

    [$customer, $customerId] = atomicCustomer($this, 'rollback-customer@example.com');

    $this->withToken($customer)->postJson('/api/v1/cart/items', ['menu_item_id' => $itemId, 'quantity' => 3])
        ->assertCreated();

    expect((int) MenuItemModel::query()->whereKey($itemId)->value('stock'))->toBe(10);

    failingDeliveryRepository();

    expect(fn () => app(CheckoutService::class)->checkout((string) $customerId, CheckoutInput::fromArray([
        'fulfilment' => 'delivery',
        'delivery_address' => [
            'line' => '5 Customer Rd', 'city' => 'Lagos', 'state' => 'Lagos',
            'location' => ['latitude' => 6.50, 'longitude' => 3.38],
        ],
    ])))->toThrow(RuntimeException::class);

    // Everything the failed run had already written is gone: the order, the
    // stock deduction and the cleared cart all roll back together.
    expect((int) MenuItemModel::query()->whereKey($itemId)->value('stock'))->toBe(10)
        ->and(OrderModel::query()->count())->toBe(0)
        ->and(DeliveryModel::query()->count())->toBe(0);

    // The cart survives, so the customer can simply try again.
    $this->withToken($customer)->getJson('/api/v1/cart')
        ->assertOk()->assertJsonPath('data.items.0.menu_item_id', $itemId);
});

it('places the order and deducts stock together on the happy path', function (): void {
    ['owner' => $owner, 'itemId' => $itemId] = atomicVendorFixture($this, 'happy');
    $this->withToken($owner)->patchJson("/api/v1/menu-items/{$itemId}/stock", ['stock' => 5])->assertOk();

    [$customer] = atomicCustomer($this, 'happy-customer@example.com');
    $this->withToken($customer)->postJson('/api/v1/cart/items', ['menu_item_id' => $itemId, 'quantity' => 2])
        ->assertCreated();

    $this->withToken($customer)->postJson('/api/v1/checkout', ['fulfilment' => 'pickup'])->assertCreated();

    expect((int) MenuItemModel::query()->whereKey($itemId)->value('stock'))->toBe(3)
        ->and(OrderModel::query()->count())->toBe(1);
});

it('returns the original order when a checkout is retried with the same idempotency key', function (): void {
    ['owner' => $owner, 'itemId' => $itemId] = atomicVendorFixture($this, 'idem');
    $this->withToken($owner)->patchJson("/api/v1/menu-items/{$itemId}/stock", ['stock' => 9])->assertOk();

    [$customer] = atomicCustomer($this, 'idem-customer@example.com');
    $this->withToken($customer)->postJson('/api/v1/cart/items', ['menu_item_id' => $itemId, 'quantity' => 2])
        ->assertCreated();

    $first = $this->withToken($customer)
        ->withHeader('Idempotency-Key', 'checkout-retry-1')
        ->postJson('/api/v1/checkout', ['fulfilment' => 'pickup'])
        ->assertCreated()->json('data');

    // The retry a flaky network would produce.
    $second = $this->withToken($customer)
        ->withHeader('Idempotency-Key', 'checkout-retry-1')
        ->postJson('/api/v1/checkout', ['fulfilment' => 'pickup'])
        ->assertOk()->json('data');

    expect($second['id'])->toBe($first['id'])
        ->and(OrderModel::query()->count())->toBe(1)
        ->and((int) MenuItemModel::query()->whereKey($itemId)->value('stock'))->toBe(7);
});
