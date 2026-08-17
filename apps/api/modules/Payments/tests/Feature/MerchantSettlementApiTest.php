<?php

declare(strict_types=1);

use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'flags.overrides.settlement.accrual' => 'true',
        'flags.overrides.settlement.accrual_posting' => 'true',
    ]);
});

/**
 * A user who owns a vendor, and the vendor's id.
 *
 * The vendor row is inserted directly rather than through the Marketplace API,
 * because what is under test here is the ownership *lookup* — the directory
 * reads `marketplace_vendors.owner_user_id`, and a fixture that went through a
 * service could hide a mismatch between what that service writes and what the
 * directory reads.
 *
 * @return array{token: string, user: string, vendor: string}
 */
function merchantUser(object $test, string $email): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Merchant Owner',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $vendorId = (string) Str::orderedUuid();
    // A random suffix, not the id prefix: ordered uuids created in the same
    // millisecond share their leading bytes, so the slug collided.
    $suffix = Str::lower(Str::random(10));
    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId,
        'owner_user_id' => $data['user']['id'],
        'name' => 'Test Kitchen '.$suffix,
        'slug' => 'test-kitchen-'.$suffix,
        'type' => 'restaurant',
        'status' => 'verified',
        'category' => 'food',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['token' => $data['tokens']['access_token'], 'user' => $data['user']['id'], 'vendor' => $vendorId];
}

function payFor(object $test, string $token, string $orderId, int $amount, string $email): void
{
    $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => $amount,
        'customer_email' => $email,
        'order_id' => $orderId,
    ])->assertCreated();
}

it('shows a merchant their own derived payable', function (): void {
    ['token' => $token, 'vendor' => $vendor] = merchantUser($this, 'owner1@example.com');

    $order = (string) Str::orderedUuid();
    payFor($this, $token, $order, 1_000_000, 'owner1@example.com');
    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($order, 'vendor', $vendor));

    $body = $this->withToken($token)
        ->getJson("/api/v1/payments/merchants/{$vendor}/payable")
        ->assertOk()->json('data');

    expect($body['merchant_id'])->toBe($vendor)
        ->and($body['currency'])->toBe('NGN')
        ->and($body['payable_minor'])->toBeGreaterThan(0)
        ->and($body['settleable'])->toBeTrue()
        ->and($body['overdrawn'])->toBeFalse();
});

it('lists a merchant their own accruals', function (): void {
    ['token' => $token, 'vendor' => $vendor] = merchantUser($this, 'owner2@example.com');

    $order = (string) Str::orderedUuid();
    payFor($this, $token, $order, 400_000, 'owner2@example.com');
    app(MerchantEarningsRecorder::class)->recordSettledOrder(new SettledOrder($order, 'vendor', $vendor));

    $body = $this->withToken($token)
        ->getJson("/api/v1/payments/merchants/{$vendor}/accruals")
        ->assertOk()->json('data');

    expect($body)->toHaveCount(1)
        ->and($body[0]['order_id'])->toBe($order)
        ->and($body[0]['type'])->toBe('earning')
        ->and($body[0]['settleable'])->toBeTrue();
});

it('answers 404 when a merchant asks about somebody else', function (): void {
    // The IDOR case, and a 404 rather than a 403 on purpose: a 403 would confirm
    // the id is real, which is all an attacker enumerating ids wants.
    ['token' => $mine] = merchantUser($this, 'owner3@example.com');
    ['vendor' => $theirs] = merchantUser($this, 'owner4@example.com');

    $this->withToken($mine)->getJson("/api/v1/payments/merchants/{$theirs}/payable")->assertStatus(404);
    $this->withToken($mine)->getJson("/api/v1/payments/merchants/{$theirs}/accruals")->assertStatus(404);
    $this->withToken($mine)->getJson("/api/v1/payments/merchants/{$theirs}/settlements")->assertStatus(404);
});

it('answers 404 for a merchant id that does not exist at all', function (): void {
    ['token' => $token] = merchantUser($this, 'owner5@example.com');

    $this->withToken($token)
        ->getJson('/api/v1/payments/merchants/'.Str::orderedUuid().'/payable')
        ->assertStatus(404);
});

it('refuses an unauthenticated merchant query', function (): void {
    ['vendor' => $vendor] = merchantUser($this, 'owner6@example.com');

    $this->getJson("/api/v1/payments/merchants/{$vendor}/payable")->assertStatus(401);
});

it('exposes no merchant endpoint that changes anything', function (): void {
    // M27 gives merchants visibility, not control. A POST appearing under
    // /merchants/ later would mean somebody can ask to be paid, which is a
    // different capability with a different threat model.
    $mutating = [];

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! str_contains($route->uri(), 'v1/payments/merchants/')) {
            continue;
        }
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $mutating[] = $route->uri();
        }
    }

    expect($mutating)->toBe([]);
});

it('finds merchant routes to check, so the read-only sweep is not vacuous', function (): void {
    $found = 0;
    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'v1/payments/merchants/')) {
            $found++;
        }
    }

    expect($found)->toBeGreaterThan(0);
});

it('accrues when a marketplace order is marked delivered', function (): void {
    // The real trigger, end to end: no direct call to the recorder anywhere in
    // this test. If the wiring in OrderService is removed, this fails and the
    // others do not.
    ['token' => $token, 'user' => $userId, 'vendor' => $vendor] = merchantUser($this, 'delivered@example.com');

    $orderId = (string) Str::orderedUuid();
    DB::table('marketplace_orders')->insert([
        'id' => $orderId,
        'reference' => 'ORD-'.substr($orderId, 0, 8),
        'customer_user_id' => $userId,
        'vendor_id' => $vendor,
        'lines' => json_encode([]),
        'subtotal_minor' => 500_000,
        'total_minor' => 500_000,
        'delivery_fee_minor' => 0,
        'currency' => 'NGN',
        'fulfilment' => 'pickup',
        'status' => 'ready',
        'status_history' => json_encode([]),
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    payFor($this, $token, $orderId, 500_000, 'delivered@example.com');

    app(\EruoFood\Marketplace\Application\Service\OrderService::class)
        ->advanceStatus($userId, false, $orderId, \EruoFood\Marketplace\Domain\Enum\OrderStatus::Dispatched);
    app(\EruoFood\Marketplace\Application\Service\OrderService::class)
        ->advanceStatus($userId, false, $orderId, \EruoFood\Marketplace\Domain\Enum\OrderStatus::Delivered);

    $accrual = app(\EruoFood\Payments\Domain\Settlement\PayableAccrualRepository::class)
        ->findEarningForOrder($orderId);

    expect($accrual)->not->toBeNull()
        ->and($accrual->merchantId())->toBe($vendor)
        ->and($accrual->gross()->minorUnits)->toBe(500_000);
});
