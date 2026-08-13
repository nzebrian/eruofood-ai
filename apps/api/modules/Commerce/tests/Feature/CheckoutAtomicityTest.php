<?php

declare(strict_types=1);

use EruoFood\Commerce\Application\Input\CheckoutInput;
use EruoFood\Commerce\Application\Service\CheckoutService;
use EruoFood\Commerce\Domain\Order\OrderRepository;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\CouponModel;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\InventoryItemModel;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\OrderModel;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M23 — grocery checkout is one transaction.
 *
 * Inventory deduction, order creation and coupon redemption used to commit
 * independently, so a failure between them could leave stock deducted and a
 * coupon spent against an order that does not exist. The coupon counter was also
 * read without a lock, letting a limited-run code be redeemed past its cap.
 */
/**
 * Self-contained fixtures — a test file that only passes when a sibling file
 * happens to be loaded is a trap for whoever next runs a single file.
 */
function atomicShopper(object $test, string $email): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Atomic Shopper',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

/** @return array{owner: string, admin: string, storeId: string, productId: string} */
function atomicStoreFixture(object $test, string $ownerEmail): array
{
    $owner = atomicShopper($test, $ownerEmail);

    $adminEmail = 'admin-'.$ownerEmail;
    atomicShopper($test, $adminEmail);
    UserModel::query()->where('email', $adminEmail)->update(['roles' => ['admin']]);
    $admin = $test->postJson('/api/v1/auth/login', ['email' => $adminEmail, 'password' => 'Password123'])
        ->json('data.tokens.access_token');

    $storeId = $test->withToken($owner)->postJson('/api/v1/commerce/stores', ['name' => 'Lagos Fresh Market'])
        ->assertCreated()->json('data.id');
    $test->withToken($admin)->postJson("/api/v1/commerce/admin/stores/{$storeId}/verify")->assertOk();

    $productId = $test->withToken($owner)->postJson("/api/v1/commerce/stores/{$storeId}/products", [
        'name' => 'Ofada Rice 5kg',
        'kind' => 'grocery',
        'department' => 'pantry',
        'base_price_minor' => 950000,
        'tags' => ['rice', 'staple'],
    ])->assertCreated()->json('data.id');

    $test->withToken($admin)->postJson("/api/v1/commerce/admin/products/{$productId}/approve")->assertOk();

    return ['owner' => $owner, 'admin' => $admin, 'storeId' => $storeId, 'productId' => $productId];
}

it('rolls back inventory and the coupon when the order cannot be written', function (): void {
    ['owner' => $owner, 'admin' => $admin, 'productId' => $productId]
        = atomicStoreFixture($this, 'com-atomic-owner@example.com');
    $customer = atomicShopper($this, 'com-atomic-customer@example.com');
    $customerId = UserModel::query()->where('email', 'com-atomic-customer@example.com')->value('id');

    $this->withToken($admin)->postJson('/api/v1/commerce/admin/coupons', [
        'code' => 'ROLLBACK10', 'type' => 'percentage', 'value' => 10, 'min_spend_minor' => 1000,
    ])->assertCreated();

    // Give the product tracked stock so there is something to deduct.
    $this->withToken($admin)->postJson('/api/v1/commerce/admin/inventory/receive', [
        'product_id' => $productId, 'quantity' => 20,
    ])->assertCreated();

    $this->withToken($customer)->postJson('/api/v1/commerce/cart/items', [
        'product_id' => $productId, 'quantity' => 2,
    ])->assertCreated();
    $this->withToken($customer)->postJson('/api/v1/commerce/cart/coupon', ['code' => 'ROLLBACK10'])->assertOk();

    $stockBefore = (int) InventoryItemModel::query()->where('product_id', $productId)->value('quantity');
    $redeemedBefore = (int) CouponModel::query()->where('code', 'ROLLBACK10')->value('times_redeemed');

    // Fail the order write, after inventory has already been deducted.
    $real = app(OrderRepository::class);
    $failing = Mockery::mock(OrderRepository::class);
    $failing->shouldReceive('nextIdentity')->andReturnUsing(static fn (): string => $real->nextIdentity());
    $failing->shouldReceive('nextReference')->andReturnUsing(static fn (): string => $real->nextReference());
    $failing->shouldReceive('save')->andThrow(new RuntimeException('storage failed writing the order'));
    app()->instance(OrderRepository::class, $failing);

    expect(fn () => app(CheckoutService::class)->place((string) $customerId, CheckoutInput::fromArray(['pickup' => true])))
        ->toThrow(RuntimeException::class);

    expect((int) InventoryItemModel::query()->where('product_id', $productId)->value('quantity'))->toBe($stockBefore)
        ->and((int) CouponModel::query()->where('code', 'ROLLBACK10')->value('times_redeemed'))->toBe($redeemedBefore)
        ->and(OrderModel::query()->count())->toBe(0);
});

it('deducts inventory and redeems the coupon exactly once on success', function (): void {
    ['admin' => $admin, 'productId' => $productId]
        = atomicStoreFixture($this, 'com-happy-owner@example.com');
    $customer = atomicShopper($this, 'com-happy-customer@example.com');

    $this->withToken($admin)->postJson('/api/v1/commerce/admin/coupons', [
        'code' => 'HAPPY10', 'type' => 'percentage', 'value' => 10, 'min_spend_minor' => 1000,
    ])->assertCreated();
    $this->withToken($admin)->postJson('/api/v1/commerce/admin/inventory/receive', [
        'product_id' => $productId, 'quantity' => 20,
    ])->assertCreated();

    $this->withToken($customer)->postJson('/api/v1/commerce/cart/items', [
        'product_id' => $productId, 'quantity' => 3,
    ])->assertCreated();
    $this->withToken($customer)->postJson('/api/v1/commerce/cart/coupon', ['code' => 'HAPPY10'])->assertOk();

    $this->withToken($customer)->postJson('/api/v1/commerce/checkout', ['pickup' => true])->assertCreated();

    expect((int) InventoryItemModel::query()->where('product_id', $productId)->value('quantity'))->toBe(17)
        ->and((int) CouponModel::query()->where('code', 'HAPPY10')->value('times_redeemed'))->toBe(1)
        ->and(OrderModel::query()->count())->toBe(1);
});

it('replays the original order when the checkout is retried with the same key', function (): void {
    ['admin' => $admin, 'productId' => $productId]
        = atomicStoreFixture($this, 'com-idem-owner@example.com');
    $customer = atomicShopper($this, 'com-idem-customer@example.com');

    $this->withToken($admin)->postJson('/api/v1/commerce/admin/inventory/receive', [
        'product_id' => $productId, 'quantity' => 10,
    ])->assertCreated();

    $this->withToken($customer)->postJson('/api/v1/commerce/cart/items', [
        'product_id' => $productId, 'quantity' => 2,
    ])->assertCreated();

    $first = $this->withToken($customer)
        ->withHeader('Idempotency-Key', 'com-checkout-1')
        ->postJson('/api/v1/commerce/checkout', ['pickup' => true])
        ->assertCreated()->json('data');

    $second = $this->withToken($customer)
        ->withHeader('Idempotency-Key', 'com-checkout-1')
        ->postJson('/api/v1/commerce/checkout', ['pickup' => true])
        ->assertOk()->json('data');

    expect($second['id'])->toBe($first['id'])
        ->and(OrderModel::query()->count())->toBe(1)
        ->and((int) InventoryItemModel::query()->where('product_id', $productId)->value('quantity'))->toBe(8);
});

it('exposes a locking read for the coupon counter', function (): void {
    ['admin' => $admin] = atomicStoreFixture($this, 'com-lock-owner@example.com');

    $this->withToken($admin)->postJson('/api/v1/commerce/admin/coupons', [
        'code' => 'LOCKED5', 'type' => 'percentage', 'value' => 5, 'min_spend_minor' => 100,
    ])->assertCreated();

    // The locking read must return the same coupon as the plain one; the lock
    // itself is exercised under real concurrency by the PostgreSQL script.
    $coupons = app(CouponRepository::class);

    expect($coupons->findByCodeForUpdate('LOCKED5')?->code())->toBe('LOCKED5')
        ->and($coupons->findByCodeForUpdate('NOPE'))->toBeNull();
});
