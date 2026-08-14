<?php

declare(strict_types=1);

use EruoFood\Geo\Application\Service\MerchantLocationService;
use EruoFood\Geo\Domain\Location\LocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M25 — merchant trading locations, and the M24 KYB seam.
 *
 * The distinction this file exists to defend: a business's **registered**
 * address (the one on the CAC filing, collected by M24 for KYB) and its
 * **trading** address are different things and are frequently different places.
 * A registered address is often an accountant's office or the owner's home.
 *
 * Only the trading address is ever published, and only coarsened. Passing KYB
 * geocodes the registered address for operations and does not put it on a
 * public listing — a verification decision is not consent to publish somebody's
 * home.
 */

/** @return array{token: string, id: string, vendorId: string} */
function merchantAccount(object $test, string $email): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Merchant Owner',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $vendorId = (string) Str::orderedUuid();

    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId,
        'owner_user_id' => $data['user']['id'],
        'name' => 'Test Kitchen',
        'slug' => 'test-kitchen-'.Str::lower(Str::random(8)),
        'type' => 'restaurant',
        'status' => 'verified',
        'category' => 'nigerian',
        'contact' => json_encode(['phone' => '+2348012345678']),
        'address' => json_encode(['line' => '12 Allen Avenue', 'city' => 'Ikeja', 'state' => 'Lagos']),
        'business_hours' => json_encode([]),
        'delivery_zones' => json_encode([]),
        'images' => json_encode([]),
        'featured' => false,
        'rating_average' => 0,
        'rating_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id'], 'vendorId' => $vendorId];
}

// ------------------------------------------------------- setting the address

it('sets a trading address and points the vendor at it', function (): void {
    $merchant = merchantAccount($this, 'merchant-1@example.com');

    $location = $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location", [
            'address' => '12 Allen Avenue, Ikeja',
            'country_code' => 'NG',
        ])
        ->assertCreated()
        ->json('data');

    expect($location['coordinates']['latitude'])->toBeFloat()
        ->and($location['is_deliverable'])->toBeTrue()
        // The pointer column M25's migration added, now populated.
        ->and(DB::table('marketplace_vendors')->where('id', $merchant['vendorId'])->value('primary_location_id'))
        ->toBe($location['id']);
});

it('lets a merchant\'s dropped pin outrank the geocode', function (): void {
    $merchant = merchantAccount($this, 'merchant-pin@example.com');

    $location = $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location", [
            'address' => '12 Allen Avenue, Ikeja',
            'latitude' => 6.6018123,
            'longitude' => 3.3515987,
        ])
        ->assertCreated()
        ->json('data');

    expect($location['coordinates']['latitude'])->toBe(6.6018123);
});

// ------------------------------------------------------------- authorisation

it('refuses to let one merchant set another merchant\'s location', function (): void {
    $mine = merchantAccount($this, 'merchant-a@example.com');
    $theirs = merchantAccount($this, 'merchant-b@example.com');

    $this->withToken($theirs['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$mine['vendorId']}/location", ['address' => '1 Nowhere Road'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'GEO_NOT_AUTHORIZED');

    expect(DB::table('marketplace_vendors')->where('id', $mine['vendorId'])->value('primary_location_id'))->toBeNull();
});

it('refuses to let one merchant read another merchant\'s exact location', function (): void {
    $mine = merchantAccount($this, 'merchant-c@example.com');
    $theirs = merchantAccount($this, 'merchant-d@example.com');

    $this->withToken($mine['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$mine['vendorId']}/location", ['address' => '12 Allen Avenue, Ikeja'])
        ->assertCreated();

    $this->withToken($theirs['token'])
        ->getJson("/api/v1/geo/merchants/vendor/{$mine['vendorId']}/location/manage")
        ->assertStatus(403);
});

// ------------------------------------------------------- public vs private

/**
 * The public sees enough to draw a pin and not enough to identify a doorway.
 * Three decimal places is about 110 metres.
 */
it('publishes a merchant location coarsely and privately in full', function (): void {
    $merchant = merchantAccount($this, 'merchant-public@example.com');

    $exact = $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location", [
            'address' => '12 Allen Avenue, Ikeja',
            'latitude' => 6.6018123,
            'longitude' => 3.3515987,
        ])
        ->assertCreated()
        ->json('data');

    $public = $this->getJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location")
        ->assertOk()
        ->json('data.location');

    expect($exact['coordinates']['latitude'])->toBe(6.6018123)
        ->and($public['coordinates']['latitude'])->toBe(6.602)
        ->and($public['coordinates']['longitude'])->toBe(3.352)
        // A client can draw an honest circle rather than a falsely precise pin.
        ->and($public['precision_metres'])->toBe(111)
        // The public payload carries no id and no exact address line.
        ->and($public)->not->toHaveKey('id')
        ->and($public)->not->toHaveKey('formatted_address');
});

it('is readable publicly without a token', function (): void {
    $merchant = merchantAccount($this, 'merchant-anon@example.com');

    $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location", ['address' => '12 Allen Avenue, Ikeja'])
        ->assertCreated();

    $this->getJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location")
        ->assertOk()
        ->assertJsonPath('data.location.locality', 'Lagos');
});

/**
 * A pin in the wrong place is worse than no pin, so a disputed location is
 * withheld rather than published imprecisely.
 */
it('withholds a disputed location from the public view', function (): void {
    $merchant = merchantAccount($this, 'merchant-disputed@example.com');

    $location = $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location", ['address' => '12 Allen Avenue, Ikeja'])
        ->assertCreated()
        ->json('data');

    app(MerchantLocationService::class)->dispute($location['id'], 'rider could not find it');

    $this->getJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location")
        ->assertOk()
        ->assertJsonPath('data.location', null);
});

it('returns nothing rather than an error for a merchant with no location', function (): void {
    $merchant = merchantAccount($this, 'merchant-none@example.com');

    $this->getJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location")
        ->assertOk()
        ->assertJsonPath('data.location', null);
});

// ---------------------------------------------------------- confirmation

/**
 * Somebody checked this deliberately, so an automated re-geocode should not
 * quietly overrule them.
 */
it('keeps a confirmed location confirmed through a later geocode', function (): void {
    $merchant = merchantAccount($this, 'merchant-confirm@example.com');

    $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location", ['address' => '12 Allen Avenue, Ikeja'])
        ->assertCreated();

    $confirmed = $this->withToken($merchant['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location/confirm")
        ->assertOk()
        ->json('data');

    expect($confirmed['status'])->toBe('confirmed')
        ->and($confirmed['verified_at'])->not->toBeNull();

    // Re-geocoding the same record must not downgrade it.
    $service = app(MerchantLocationService::class);
    $repository = app(LocationRepository::class);
    $location = $repository->findById($confirmed['id']);

    $location->applyGeocode(
        new EruoFood\Geo\Domain\ValueObject\Coordinates(6.6019, 3.3516),
        new EruoFood\Geo\Domain\ValueObject\PostalAddress(formatted: 'Re-geocoded'),
        EruoFood\Geo\Domain\Enum\LocationPrecision::Approximate,
        'mock',
        null,
        new DateTimeImmutable(),
    );
    $repository->save($location);

    expect($service->get($confirmed['id'])->status()->value)->toBe('confirmed');
});

// ------------------------------------------------------------- the M24 seam

/**
 * The gap M24 left open: it created `latitude`/`longitude` columns on
 * `verification_business_profiles` and never populated them, because there was
 * no geocoder. Now there is — and the result stays private.
 */
it('geocodes a KYB registered address privately, never onto the public listing', function (): void {
    $merchant = merchantAccount($this, 'merchant-kyb@example.com');

    $profileId = (string) Str::orderedUuid();

    DB::table('verification_business_profiles')->insert([
        'id' => $profileId,
        'business_kind' => 'vendor',
        'business_id' => $merchant['vendorId'],
        'country_code' => 'NG',
        'registered_name' => 'Test Kitchen Limited',
        'trading_name' => 'Test Kitchen',
        'business_type' => 'limited_company',
        'registration_number' => 'RC1234567',
        'registration_authority' => 'CAC',
        // The registered address: an accountant's office, not the restaurant.
        'address' => json_encode(['line1' => '9 Adeola Hopewell', 'city' => 'Victoria Island', 'state' => 'Lagos']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $location = app(MerchantLocationService::class)->geocodeRegisteredAddress(
        $profileId,
        ['line1' => '9 Adeola Hopewell', 'city' => 'Victoria Island', 'state' => 'Lagos'],
        'NG',
    );

    expect($location)->not->toBeNull()
        ->and($location->coordinates())->not->toBeNull()
        // Attached to the verification profile...
        ->and(DB::table('verification_business_profiles')->where('id', $profileId)->value('location_id'))
        ->toBe($location->id())
        // ...and emphatically NOT to the vendor's public listing.
        ->and(DB::table('marketplace_vendors')->where('id', $merchant['vendorId'])->value('primary_location_id'))
        ->toBeNull();

    $this->getJson("/api/v1/geo/merchants/vendor/{$merchant['vendorId']}/location")
        ->assertOk()
        ->assertJsonPath('data.location', null);
});

/**
 * A verified business whose address the geocoder could not resolve is still a
 * verified business. Letting a mapping outage propagate would turn somebody
 * else's downtime into a blocked merchant onboarding.
 */
it('keeps an unresolvable registered address as a record rather than failing', function (): void {
    $profileId = (string) Str::orderedUuid();

    DB::table('verification_business_profiles')->insert([
        'id' => $profileId,
        'business_kind' => 'vendor',
        'business_id' => (string) Str::orderedUuid(),
        'country_code' => 'NG',
        'registered_name' => 'Ghost Limited',
        'trading_name' => 'Ghost',
        'business_type' => 'limited_company',
        'registration_number' => 'RC7654321',
        'registration_authority' => 'CAC',
        'address' => json_encode(['line1' => 'nowhere at all']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // "nowhere" makes the mock provider report not-found.
    $location = app(MerchantLocationService::class)->geocodeRegisteredAddress(
        $profileId,
        ['line1' => 'nowhere at all'],
        'NG',
    );

    expect($location)->not->toBeNull()
        // Unresolved but preserved, so somebody can see what needs correcting.
        ->and($location->coordinates())->toBeNull()
        ->and($location->needsGeocoding())->toBeTrue()
        ->and(DB::table('verification_business_profiles')->where('id', $profileId)->value('location_id'))
        ->toBe($location->id());
});

it('returns nothing for a registered address with no usable text', function (): void {
    expect(app(MerchantLocationService::class)->geocodeRegisteredAddress((string) Str::orderedUuid(), [], 'NG'))
        ->toBeNull();
});
