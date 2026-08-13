<?php

declare(strict_types=1);

use EruoFood\Commerce\Application\Input\CheckoutInput as CommerceCheckoutInput;
use EruoFood\Commerce\Application\Service\CheckoutService as CommerceCheckoutService;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Marketplace\Application\Input\CheckoutInput;
use EruoFood\Marketplace\Application\Service\CheckoutService;
use EruoFood\Verification\Application\Service\EligibilityService;
use EruoFood\Verification\Application\Service\ReviewService;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\EloquentVerificationStatusQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M24 — the activation gate.
 *
 * This is the file that decides whether shipping M24 is safe. The platform
 * already has live merchants and riders who have never been through KYC, and
 * deploying a verification requirement that takes effect on deploy would delist
 * all of them at once. So enforcement is a separate switch from verification
 * itself, defaulting to off, and these tests assert both halves: that nothing
 * changes for anyone until the switch is thrown, and that the gate genuinely
 * closes once it is.
 */

/** Turn enforcement on or off mid-test and re-resolve the services that read it. */
function setEnforcement(array $flags): void
{
    config()->set('verification.enforcement', $flags);

    // The flags are injected at construction, so the singletons holding the old
    // values have to go.
    app()->forgetInstance(EligibilityService::class);
    app()->forgetInstance(VerificationStatusQuery::class);
    app()->forgetInstance(EloquentVerificationStatusQuery::class);
}

/** Mark a subject verified through the real state machine, not by writing rows. */
function markVerified(SubjectType $type, string $subjectId, CaseType $caseType): void
{
    $service = app(VerificationService::class);
    $case = $service->openCase($type, $subjectId, $caseType, 'NG');
    $started = $service->startVerification($case->id(), ['document']);

    // Through the reviewer path, so the case ends up verified the same way a
    // real one does — including the projection event consumers depend on.
    app(ReviewService::class)->approve($started->id(), 'test-admin');
}

function enforcementUser(object $test, string $email, bool $admin = false): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Enforcement User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    if ($admin) {
        UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);
        $token = $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
            ->json('data.tokens.access_token');

        return ['token' => $token, 'id' => $data['user']['id']];
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

/** @return array{owner: string, admin: string, vendorId: string, itemId: string} */
function enforcementVendor(object $test, string $suffix): array
{
    ['token' => $owner] = enforcementUser($test, "v-owner-{$suffix}@example.com");
    ['token' => $admin] = enforcementUser($test, "v-admin-{$suffix}@example.com", admin: true);

    $vendorId = $test->withToken($owner)->postJson('/api/v1/vendors', [
        'name' => "Gate Kitchen {$suffix}",
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

    return ['owner' => $owner, 'admin' => $admin, 'vendorId' => $vendorId, 'itemId' => $itemId];
}

/** Place a marketplace order as a fresh customer; returns the thrown exception or null. */
function attemptOrder(object $test, string $itemId, string $email): ?Throwable
{
    ['token' => $customer, 'id' => $customerId] = enforcementUser($test, $email);

    $test->withToken($customer)->postJson('/api/v1/cart/items', ['menu_item_id' => $itemId, 'quantity' => 1])
        ->assertCreated();

    try {
        app(CheckoutService::class)->checkout((string) $customerId, CheckoutInput::fromArray([
            'fulfilment' => 'delivery',
            'delivery_address' => [
                'line' => '2 Customer Road', 'city' => 'Lagos', 'state' => 'Lagos',
                'location' => ['latitude' => 6.5, 'longitude' => 3.4],
            ],
        ]));
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

// -------------------------------------------- the default: nothing changes --

it('ships with enforcement off so no existing business is locked out', function (): void {
    // The shipped configuration, read as the application reads it.
    expect(config('verification.enforcement.enabled'))->toBeFalse();

    ['itemId' => $itemId, 'vendorId' => $vendorId] = enforcementVendor($this, 'default');

    // The vendor has no KYB case at all — exactly the state every existing
    // merchant will be in on the day this deploys.
    expect(app(VerificationStatusQuery::class)->statusFor('business', $vendorId))->toBe('not_started');

    expect(attemptOrder($this, $itemId, 'default-customer@example.com'))->toBeNull();
});

it('still records verification status while enforcement is off', function (): void {
    ['vendorId' => $vendorId] = enforcementVendor($this, 'observe');

    markVerified(SubjectType::Business, $vendorId, CaseType::Business);

    // The observation phase of the rollout: statuses accumulate and can be
    // reviewed long before the switch is thrown.
    expect(app(VerificationStatusQuery::class)->statusFor('business', $vendorId))->toBe('verified')
        ->and(app(VerificationStatusQuery::class)->blocksSubject('business', $vendorId, 'restaurant'))->toBeFalse();
});

// ------------------------------------------------- restaurants (Marketplace) --

it('blocks ordering from an unverified restaurant once enforcement is on', function (): void {
    ['itemId' => $itemId] = enforcementVendor($this, 'block');

    setEnforcement(['enabled' => true]);

    $error = attemptOrder($this, $itemId, 'block-customer@example.com');

    expect($error)->not->toBeNull()
        ->and($error->getMessage())->toContain('verification');
});

it('allows ordering from a verified restaurant under enforcement', function (): void {
    ['itemId' => $itemId, 'vendorId' => $vendorId] = enforcementVendor($this, 'allow');

    markVerified(SubjectType::Business, $vendorId, CaseType::Business);
    setEnforcement(['enabled' => true]);

    expect(attemptOrder($this, $itemId, 'allow-customer@example.com'))->toBeNull();
});

it('gates restaurants and groceries independently', function (): void {
    ['itemId' => $itemId] = enforcementVendor($this, 'split');

    // A staged rollout: groceries first, restaurants still observing.
    setEnforcement(['enabled' => false, 'groceries' => true]);

    $someStore = (string) Illuminate\Support\Str::uuid();
    $someVendor = (string) Illuminate\Support\Str::uuid();

    expect(attemptOrder($this, $itemId, 'split-customer@example.com'))->toBeNull()
        ->and(app(VerificationStatusQuery::class)->blocksSubject('business', $someStore, 'grocery'))->toBeTrue()
        ->and(app(VerificationStatusQuery::class)->blocksSubject('business', $someVendor, 'restaurant'))->toBeFalse();
});

it('lets the master switch be overridden downwards for one population', function (): void {
    setEnforcement(['enabled' => true, 'riders' => false]);

    $eligibility = app(EligibilityService::class);

    // Everything gated except riders — the reverse-order rollout.
    expect($eligibility->enforcedFor(SubjectType::Rider))->toBeFalse()
        ->and($eligibility->enforcedForBusinessKind('restaurant'))->toBeTrue()
        ->and($eligibility->enforcedForBusinessKind('grocery'))->toBeTrue();
});

// ------------------------------------------------------- groceries (Commerce) --

it('blocks a grocery order from an unverified store once enforcement is on', function (): void {
    ['token' => $owner] = enforcementUser($this, 'g-owner@example.com');
    ['token' => $admin] = enforcementUser($this, 'g-admin@example.com', admin: true);

    $storeId = $this->withToken($owner)->postJson('/api/v1/commerce/stores', ['name' => 'Lagos Fresh'])
        ->assertCreated()->json('data.id');
    $this->withToken($admin)->postJson("/api/v1/commerce/admin/stores/{$storeId}/verify")->assertOk();

    $productId = $this->withToken($owner)->postJson("/api/v1/commerce/stores/{$storeId}/products", [
        'name' => 'Ofada Rice 5kg', 'kind' => 'grocery', 'department' => 'pantry',
        'base_price_minor' => 950000, 'tags' => ['rice'],
    ])->assertCreated()->json('data.id');
    $this->withToken($admin)->postJson("/api/v1/commerce/admin/products/{$productId}/approve")->assertOk();
    $this->withToken($admin)->postJson('/api/v1/commerce/admin/inventory/receive', [
        'product_id' => $productId, 'quantity' => 10,
    ])->assertCreated();

    ['token' => $shopper, 'id' => $shopperId] = enforcementUser($this, 'g-shopper@example.com');
    $this->withToken($shopper)->postJson('/api/v1/commerce/cart/items', [
        'product_id' => $productId, 'quantity' => 1,
    ])->assertCreated();

    setEnforcement(['enabled' => true]);

    // Before M24, grocery checkout never consulted store standing at all — only
    // product publishing did — so a suspended store kept taking orders.
    expect(fn () => app(CommerceCheckoutService::class)
        ->place((string) $shopperId, CommerceCheckoutInput::fromArray(['pickup' => true])))
        ->toThrow(EruoFood\Commerce\Domain\Exception\CommerceInvalidState::class);

    // …and once the store's KYB is verified, the same basket goes through.
    markVerified(SubjectType::Business, $storeId, CaseType::Business);
    setEnforcement(['enabled' => true]);

    $order = app(CommerceCheckoutService::class)
        ->place((string) $shopperId, CommerceCheckoutInput::fromArray(['pickup' => true]));

    expect($order->id())->not->toBeEmpty();
});

// ------------------------------------------------------------------ riders --

it('keeps an unverified rider offline once enforcement is on', function (): void {
    ['token' => $rider] = enforcementUser($this, 'rider-block@example.com');

    $this->withToken($rider)->postJson('/api/v1/riders', [
        'name' => 'Chidi Rider', 'phone' => '+2348000000001', 'vehicle_type' => 'motorbike',
    ])->assertCreated();

    setEnforcement(['enabled' => true]);

    $this->withToken($rider)->patchJson('/api/v1/riders/me/status', ['status' => 'available'])
        ->assertStatus(422);

    // Going offline is never blocked — a rider must not be trapped on-shift by
    // a lapsed document.
    $this->withToken($rider)->patchJson('/api/v1/riders/me/status', ['status' => 'offline'])->assertOk();
});

it('lets a verified rider go online under enforcement', function (): void {
    ['token' => $rider, 'id' => $riderUserId] = enforcementUser($this, 'rider-allow@example.com');

    $this->withToken($rider)->postJson('/api/v1/riders', [
        'name' => 'Ada Rider', 'phone' => '+2348000000002', 'vehicle_type' => 'motorbike',
    ])->assertCreated();

    markVerified(SubjectType::Rider, (string) $riderUserId, CaseType::Identity);
    setEnforcement(['enabled' => true]);

    $this->withToken($rider)->patchJson('/api/v1/riders/me/status', ['status' => 'available'])->assertOk();
});

it('leaves riders alone while enforcement is off', function (): void {
    ['token' => $rider] = enforcementUser($this, 'rider-default@example.com');

    $this->withToken($rider)->postJson('/api/v1/riders', [
        'name' => 'Existing Rider', 'phone' => '+2348000000003', 'vehicle_type' => 'bicycle',
    ])->assertCreated();

    // The whole existing rider fleet on deployment day.
    $this->withToken($rider)->patchJson('/api/v1/riders/me/status', ['status' => 'available'])->assertOk();
});

it('stops giving work to a rider whose verification lapses', function (): void {
    ['token' => $rider, 'id' => $riderUserId] = enforcementUser($this, 'rider-lapse@example.com');

    $this->withToken($rider)->postJson('/api/v1/riders', [
        'name' => 'Lapsing Rider', 'phone' => '+2348000000004', 'vehicle_type' => 'motorbike',
    ])->assertCreated();

    markVerified(SubjectType::Rider, (string) $riderUserId, CaseType::Identity);
    setEnforcement(['enabled' => true]);

    $this->withToken($rider)->patchJson('/api/v1/riders/me/status', ['status' => 'available'])->assertOk();

    // Compliance demands re-verification — checked at the moment of dispatch,
    // so nobody has to remember to take the rider off-shift.
    $case = app(VerificationService::class)
        ->latestFor(SubjectType::Rider, (string) $riderUserId, CaseType::Identity);
    app(ReviewService::class)
        ->requireReverification((string) $case?->id(), 'compliance-officer');

    expect(app(VerificationStatusQuery::class)->blocksSubject('rider', (string) $riderUserId))->toBeTrue();

    $this->withToken($rider)->patchJson('/api/v1/riders/me/status', ['status' => 'available'])
        ->assertStatus(422);
});

// ------------------------------------------------------------- the read side --

it('prefers a verified case over a newer in-flight one', function (): void {
    ['token' => $rider, 'id' => $riderUserId] = enforcementUser($this, 'rider-inflight@example.com');

    markVerified(SubjectType::Rider, (string) $riderUserId, CaseType::Identity);

    // A voluntary re-check must not make somebody instantly ineligible while it
    // is in progress.
    $service = app(VerificationService::class);
    $fresh = $service->openCase(SubjectType::Rider, (string) $riderUserId, CaseType::Identity, 'NG');
    $service->startVerification($fresh->id(), ['document']);

    setEnforcement(['enabled' => true]);

    expect(app(VerificationStatusQuery::class)->statusFor('rider', (string) $riderUserId))->toBe('verified')
        ->and(app(VerificationStatusQuery::class)->blocksSubject('rider', (string) $riderUserId))->toBeFalse();
});

it('treats an unknown subject kind as nothing to enforce', function (): void {
    setEnforcement(['enabled' => true]);

    // A typo in a caller's subject type must not silently block a real
    // operation — and must not silently pass either, which is why every real
    // caller passes an enum-backed value.
    expect(app(VerificationStatusQuery::class)->blocksSubject('spaceship', 'x'))->toBeFalse();
});
