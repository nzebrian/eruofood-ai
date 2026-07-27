<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** Register a user and return a fresh access token (shared by the Commerce suite). */
function comUserToken(object $test, string $email = 'comshopper@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Com Shopper',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

function comAdminToken(object $test, string $email = 'comadmin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Com Admin',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();
    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');
}

/**
 * A verified store owned by a fresh user, with one published product.
 *
 * @return array{owner: string, admin: string, storeId: string, productId: string}
 */
function comVerifiedStoreWithProduct(object $test, string $ownerEmail = 'comowner@example.com'): array
{
    $owner = comUserToken($test, $ownerEmail);
    $admin = comAdminToken($test, 'flowadmin-'.$ownerEmail);

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

    // Requires admin approval to go live.
    $test->withToken($admin)->postJson("/api/v1/commerce/admin/products/{$productId}/approve")
        ->assertOk()->assertJsonPath('data.status', 'published');

    return ['owner' => $owner, 'admin' => $admin, 'storeId' => $storeId, 'productId' => $productId];
}

it('onboards a store, gates trading on verification, and approves products', function (): void {
    $owner = comUserToken($this, 'gate@example.com');
    $storeId = $this->withToken($owner)->postJson('/api/v1/commerce/stores', ['name' => 'Pending Store'])
        ->assertCreated()->json('data.id');

    // Unverified store cannot publish products.
    $this->withToken($owner)->postJson("/api/v1/commerce/stores/{$storeId}/products", [
        'name' => 'Test', 'kind' => 'general', 'base_price_minor' => 1000,
    ])->assertStatus(422);
});

it('publishes an approved product to the public catalogue and search', function (): void {
    ['productId' => $productId] = comVerifiedStoreWithProduct($this);

    // Public product search finds the published grocery item.
    $this->getJson('/api/v1/commerce/products?department=pantry')
        ->assertOk()
        ->assertJsonPath('data.0.id', $productId);

    // Public detail includes a related list.
    $this->getJson('/api/v1/commerce/products?q=ofada')->assertOk()->assertJsonCount(1, 'data');
});

it('accepts one review per user and rolls up the rating', function (): void {
    ['productId' => $productId] = comVerifiedStoreWithProduct($this, 'reviewowner@example.com');
    $customer = comUserToken($this, 'reviewer@example.com');

    $this->withToken($customer)->postJson("/api/v1/commerce/products/{$productId}/reviews", ['rating' => 5, 'comment' => 'Great'])
        ->assertCreated();
    // A second review by the same user conflicts.
    $this->withToken($customer)->postJson("/api/v1/commerce/products/{$productId}/reviews", ['rating' => 4])
        ->assertStatus(409);

    $this->getJson("/api/v1/commerce/products/{$productId}/reviews")->assertOk()->assertJsonCount(1, 'data');
});
