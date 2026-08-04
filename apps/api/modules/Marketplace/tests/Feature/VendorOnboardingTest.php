<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** Register a user and return a fresh access token (shared by the Marketplace suite). */
function mktUserToken(object $test, string $email = 'shopper@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Shopper',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

function mktAdminToken(object $test, string $email = 'mktadmin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Mkt Admin',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();
    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');
}

/** @return array<string, mixed> */
function vendorPayload(string $name = 'Mama Put Kitchen'): array
{
    return [
        'name' => $name,
        'type' => 'restaurant',
        'category' => 'african',
        'contact' => ['phone' => '+2348000000000', 'email' => 'hi@example.com'],
        'address' => [
            'line' => '1 Demo Street',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'location' => ['latitude' => 6.4550, 'longitude' => 3.3841],
        ],
    ];
}

it('registers a vendor as pending, then an admin verifies it into search', function (): void {
    $owner = mktUserToken($this, 'owner@example.com');

    $vendor = $this->withToken($owner)->postJson('/api/v1/vendors', vendorPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->json('data');

    // Pending vendors are not publicly listed.
    $this->getJson('/api/v1/vendors?q=mama')->assertOk()->assertJsonCount(0, 'data');

    // Admin verifies.
    $admin = mktAdminToken($this);
    $this->withToken($admin)->postJson("/api/v1/admin/marketplace/vendors/{$vendor['id']}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    // Now discoverable.
    $this->getJson('/api/v1/vendors?q=mama')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Mama Put Kitchen');
});

it('blocks non-admins from verifying a vendor', function (): void {
    $owner = mktUserToken($this, 'owner2@example.com');
    $id = $this->withToken($owner)->postJson('/api/v1/vendors', vendorPayload('Naija Grills'))
        ->assertCreated()->json('data.id');

    $this->withToken($owner)->postJson("/api/v1/admin/marketplace/vendors/{$id}/verify")->assertStatus(403);
});

it('finds nearby vendors by geolocation', function (): void {
    $owner = mktUserToken($this, 'owner3@example.com');
    $admin = mktAdminToken($this, 'mktadmin3@example.com');
    $id = $this->withToken($owner)->postJson('/api/v1/vendors', vendorPayload('Island Eats'))->json('data.id');
    $this->withToken($admin)->postJson("/api/v1/admin/marketplace/vendors/{$id}/verify")->assertOk();

    $this->getJson('/api/v1/search/vendors?lat=6.4560&lng=3.3850&radius_km=5')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Island Eats');
});
