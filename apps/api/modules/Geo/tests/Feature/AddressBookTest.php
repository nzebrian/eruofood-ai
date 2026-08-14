<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\Address\CustomerAddressRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\CustomerAddressModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M25 — the customer address book.
 *
 * Two properties dominate this file.
 *
 * **Nobody reaches anybody else's addresses.** An address is a UUID away from
 * being guessed, and a saved address is a home. Every endpoint takes its owner
 * from the token and never from the request, and the IDOR tests below check
 * that a valid id belonging to somebody else is answered as not-found rather
 * than forbidden — a 403 confirms the id is real, which is exactly what an
 * enumeration attack is looking for.
 *
 * **A phone's position is not an address.** Device coordinates bias
 * suggestions. They are never saved, never returned as an address, and never
 * become a delivery destination without an explicit act. Conflating the two is
 * how dinner arrives at the office somebody was standing outside.
 */

/** @return array{token: string, id: string} */
function geoUser(object $test, string $email): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Test Person',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

/** @return array<string, mixed> */
function saveAddress(object $test, string $token, string $address = '12 Allen Avenue, Ikeja', array $extra = []): array
{
    return $test->withToken($token)
        ->postJson('/api/v1/geo/addresses', array_merge([
            'address' => $address,
            'label' => 'home',
            'country_code' => 'NG',
        ], $extra))
        ->assertCreated()
        ->json('data');
}

// -------------------------------------------------------------------- basics

it('saves an address and geocodes it on the way in', function (): void {
    $user = geoUser($this, 'addr-1@example.com');

    $address = saveAddress($this, $user['token']);

    expect($address['label'])->toBe('home')
        // Geocoded now rather than lazily: an address that has never resolved
        // cannot be delivered to, and the customer should learn that while
        // they are looking at the form — not at checkout.
        ->and($address['location'])->not->toBeNull()
        ->and($address['location']['coordinates']['latitude'])->toBeFloat()
        ->and($address['location']['is_deliverable'])->toBeTrue()
        // The first address becomes the default: an address book with no
        // default makes every later checkout ask a question with one answer.
        ->and($address['is_default'])->toBeTrue();
});

it('keeps delivery instructions for the owner and nowhere else', function (): void {
    $user = geoUser($this, 'addr-2@example.com');

    $address = saveAddress($this, $user['token'], extra: [
        'delivery_instructions' => 'blue gate, ask for Musa',
        'contact_phone' => '+2348012345678',
    ]);

    expect($address['delivery_instructions'])->toBe('blue gate, ask for Musa')
        ->and($address['contact_phone'])->toBe('+2348012345678');
});

it('rejects an address with nothing to resolve', function (): void {
    $user = geoUser($this, 'addr-3@example.com');

    $this->withToken($user['token'])
        ->postJson('/api/v1/geo/addresses', ['address' => 'a', 'label' => 'home'])
        ->assertStatus(422);
});

it('requires a name for an address labelled other', function (): void {
    $user = geoUser($this, 'addr-4@example.com');

    $this->withToken($user['token'])
        ->postJson('/api/v1/geo/addresses', [
            'address' => '12 Allen Avenue, Ikeja',
            'label' => 'other',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'GEO_INVALID_STATE');
});

// --------------------------------------------------------------------- IDOR

/**
 * The core object-level check. A UUID is not an entitlement.
 */
it('refuses to show one customer another customer\'s address', function (): void {
    $mine = geoUser($this, 'owner@example.com');
    $theirs = geoUser($this, 'attacker@example.com');

    $address = saveAddress($this, $mine['token']);

    $this->withToken($theirs['token'])
        ->getJson('/api/v1/geo/addresses/'.$address['id'])
        // Not-found, not forbidden: a 403 would confirm the id is real.
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'GEO_RESOURCE_NOT_FOUND');
});

it('refuses every mutation of another customer\'s address', function (string $method, string $suffix, array $body): void {
    $mine = geoUser($this, 'owner-'.md5($method.$suffix).'@example.com');
    $theirs = geoUser($this, 'attacker-'.md5($method.$suffix).'@example.com');

    $address = saveAddress($this, $mine['token']);

    $this->withToken($theirs['token'])
        ->json($method, '/api/v1/geo/addresses/'.$address['id'].$suffix, $body)
        ->assertStatus(404);

    // And the address is untouched.
    expect(CustomerAddressModel::query()->find($address['id'])->is_active)->toBeTrue();
})->with([
    ['PATCH', '', ['label' => 'work']],
    ['POST', '/default', []],
    ['POST', '/relocate', ['address' => '4 Adeola Odeku, Victoria Island']],
    ['DELETE', '', []],
]);

it('lists only the caller\'s own addresses', function (): void {
    $mine = geoUser($this, 'list-mine@example.com');
    $theirs = geoUser($this, 'list-theirs@example.com');

    saveAddress($this, $mine['token'], '12 Allen Avenue, Ikeja');
    saveAddress($this, $theirs['token'], '4 Adeola Odeku, Victoria Island');

    $listed = $this->withToken($mine['token'])->getJson('/api/v1/geo/addresses')->assertOk()->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['location']['formatted_address'])->toContain('Allen');
});

it('requires authentication for the whole address book', function (): void {
    $this->getJson('/api/v1/geo/addresses')->assertStatus(401);
    $this->postJson('/api/v1/geo/addresses', ['address' => 'x', 'label' => 'home'])->assertStatus(401);
});

// ------------------------------------------------- device position vs address

/**
 * The separation this whole module insists on. A phone's coordinates bias
 * autocomplete and nothing else — they never become a saved address, and the
 * suggestion endpoint never writes one.
 */
it('never turns a device position into a saved address', function (): void {
    $user = geoUser($this, 'device@example.com');

    $this->withToken($user['token'])
        ->getJson('/api/v1/geo/autocomplete?q=Allen&device_latitude=6.4550&device_longitude=3.3841')
        ->assertOk();

    expect(CustomerAddressModel::query()->where('user_id', $user['id'])->count())->toBe(0)
        ->and($this->withToken($user['token'])->getJson('/api/v1/geo/addresses')->json('data'))->toBe([]);
});

it('suggests addresses without spending a call on a fragment', function (): void {
    $user = geoUser($this, 'autocomplete@example.com');

    $this->withToken($user['token'])
        ->getJson('/api/v1/geo/autocomplete?q=Al')
        // Two characters suggest nothing useful and cost the same as twenty.
        ->assertStatus(422);

    $suggestions = $this->withToken($user['token'])
        ->getJson('/api/v1/geo/autocomplete?q=Allen')
        ->assertOk()
        ->json('data');

    expect($suggestions)->not->toBeEmpty()
        ->and($suggestions[0])->toHaveKeys(['description', 'provider_place_id']);
});

// ------------------------------------------------------------------ defaults

it('moves the default cleanly, never leaving two or none', function (): void {
    $user = geoUser($this, 'default@example.com');

    $first = saveAddress($this, $user['token'], '12 Allen Avenue, Ikeja');
    $second = saveAddress($this, $user['token'], '4 Adeola Odeku, Victoria Island', ['label' => 'work']);

    $this->withToken($user['token'])->postJson('/api/v1/geo/addresses/'.$second['id'].'/default')->assertOk();

    $defaults = CustomerAddressModel::query()->where('user_id', $user['id'])->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()->id)->toBe($second['id'])
        ->and(app(CustomerAddressRepository::class)->defaultFor($user['id'])->id())->toBe($second['id'])
        ->and($first['is_default'])->toBeTrue(); // it was, before the move
});

/**
 * Removing the default must not leave a customer with none — the next checkout
 * would then have nothing to preselect for no reason they could see.
 */
it('promotes a survivor when the default is removed', function (): void {
    $user = geoUser($this, 'promote@example.com');

    $first = saveAddress($this, $user['token'], '12 Allen Avenue, Ikeja');
    $second = saveAddress($this, $user['token'], '4 Adeola Odeku, Victoria Island', ['label' => 'work']);

    $this->withToken($user['token'])->deleteJson('/api/v1/geo/addresses/'.$first['id'])->assertNoContent();

    expect(app(CustomerAddressRepository::class)->defaultFor($user['id'])->id())->toBe($second['id']);
});

/**
 * Deactivated, never deleted: historical orders point here, and an order whose
 * destination vanished is one nobody can investigate when a customer disputes
 * it.
 */
it('deactivates rather than deletes a removed address', function (): void {
    $user = geoUser($this, 'remove@example.com');

    $address = saveAddress($this, $user['token']);

    $this->withToken($user['token'])->deleteJson('/api/v1/geo/addresses/'.$address['id'])->assertNoContent();

    $row = CustomerAddressModel::query()->find($address['id']);

    expect($row)->not->toBeNull()
        ->and($row->is_active)->toBeFalse()
        ->and($this->withToken($user['token'])->getJson('/api/v1/geo/addresses')->json('data'))->toBe([]);
});

// ------------------------------------------------------------------ updating

it('updates a label and instructions in place', function (): void {
    $user = geoUser($this, 'update@example.com');
    $address = saveAddress($this, $user['token']);

    $updated = $this->withToken($user['token'])
        ->patchJson('/api/v1/geo/addresses/'.$address['id'], [
            'label' => 'other',
            'custom_name' => 'Mum',
            'delivery_instructions' => 'green door',
        ])
        ->assertOk()
        ->json('data');

    expect($updated['label'])->toBe('other')
        ->and($updated['display_name'])->toBe('Mum')
        ->and($updated['delivery_instructions'])->toBe('green door');
});

/**
 * A new location record rather than an edit of the old one: past orders
 * reference the old geocode, and rewriting it in place would silently change
 * where a completed delivery says it went.
 */
it('creates a new location when an address is relocated', function (): void {
    $user = geoUser($this, 'relocate@example.com');
    $address = saveAddress($this, $user['token'], '12 Allen Avenue, Ikeja');

    $relocated = $this->withToken($user['token'])
        ->postJson('/api/v1/geo/addresses/'.$address['id'].'/relocate', [
            'address' => '4 Adeola Odeku, Victoria Island',
            'country_code' => 'NG',
        ])
        ->assertOk()
        ->json('data');

    expect($relocated['location']['id'])->not->toBe($address['location']['id'])
        ->and($relocated['id'])->toBe($address['id']);
});

/**
 * A customer who dragged the marker onto their gate knows something the
 * geocoder does not, and overruling them with a rooftop match two streets away
 * is how an address that "looks right" fails every delivery.
 */
it('lets an explicit pin outrank the geocoded point', function (): void {
    $user = geoUser($this, 'pin@example.com');

    $address = saveAddress($this, $user['token'], extra: [
        'latitude' => 6.4550123,
        'longitude' => 3.3841987,
    ]);

    expect($address['location']['coordinates']['latitude'])->toBe(6.4550123)
        ->and($address['location']['coordinates']['longitude'])->toBe(3.3841987);
});
