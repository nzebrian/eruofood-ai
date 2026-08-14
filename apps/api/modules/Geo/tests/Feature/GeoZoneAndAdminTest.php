<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\Service\GeocodingService;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\ProviderRequestModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M25 — delivery zones and the Global Command Centre read surfaces.
 *
 * The zone tests turn on ordering. A restricted zone must be consulted before
 * the broad service area it sits inside, or the exclusion never fires and the
 * platform promises a delivery it cannot make.
 *
 * The admin tests turn on two things: that the surfaces are gated on a real
 * permission, and that the telemetry they are built from contains no
 * coordinates and no address text — because this is a surface operators and
 * analysts will export and graph, and none of them need to know where a
 * particular customer lives.
 */

/** @return array{token: string, id: string, vendorId: string} */
function zoneMerchant(object $test, string $email, array $roles = []): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Zone Owner',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    if ($roles !== []) {
        app(AdminAccountRepository::class)->save(AdminAccount::grant($data['user']['id'], $roles, new DateTimeImmutable()));
    }

    $vendorId = (string) Str::orderedUuid();

    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId,
        'owner_user_id' => $data['user']['id'],
        'name' => 'Zone Kitchen',
        'slug' => 'zone-kitchen-'.Str::lower(Str::random(8)),
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

// ------------------------------------------------------------------- zones

it('creates a radius zone and reports a point inside it as serviceable', function (): void {
    $merchant = zoneMerchant($this, 'zone-1@example.com');
    $base = "/api/v1/geo/merchants/vendor/{$merchant['vendorId']}";

    $this->withToken($merchant['token'])->postJson("{$base}/zones", [
        'name' => 'Ikeja 5km',
        'zone_type' => 'radius',
        'latitude' => 6.4550,
        'longitude' => 3.3841,
        'radius_metres' => 5_000,
        'fee_minor' => 50_000,
    ])->assertCreated();

    $inside = $this->withToken($merchant['token'])
        ->postJson("{$base}/zones/check", ['latitude' => 6.4560, 'longitude' => 3.3850])
        ->assertOk()->json('data');

    $outside = $this->withToken($merchant['token'])
        ->postJson("{$base}/zones/check", ['latitude' => 6.4281, 'longitude' => 3.4219])
        ->assertOk()->json('data');

    expect($inside['is_serviceable'])->toBeTrue()
        ->and($inside['zone']['name'])->toBe('Ikeja 5km')
        ->and($inside['zone']['fee_minor'])->toBe(50_000)
        ->and($outside['is_serviceable'])->toBeFalse()
        ->and($outside['zone'])->toBeNull();
});

/**
 * The ordering test. Without priority the broad inclusion matches first, the
 * exclusion never runs, and the platform accepts an order it cannot deliver.
 */
it('consults a restricted zone before the service area it sits inside', function (): void {
    $merchant = zoneMerchant($this, 'zone-restricted@example.com');
    $base = "/api/v1/geo/merchants/vendor/{$merchant['vendorId']}";

    $this->withToken($merchant['token'])->postJson("{$base}/zones", [
        'name' => 'Lagos mainland',
        'zone_type' => 'radius',
        'latitude' => 6.4550,
        'longitude' => 3.3841,
        'radius_metres' => 20_000,
        'priority' => 100,
    ])->assertCreated();

    $this->withToken($merchant['token'])->postJson("{$base}/zones", [
        'name' => 'Restricted estate',
        'zone_type' => 'polygon',
        // [lon, lat] — GeoJSON order.
        'polygon' => [[3.3800, 6.4500], [3.3900, 6.4500], [3.3900, 6.4600], [3.3800, 6.4600]],
        'is_restricted' => true,
    ])->assertCreated();

    // A point inside both. The exclusion wins.
    $inEstate = $this->withToken($merchant['token'])
        ->postJson("{$base}/zones/check", ['latitude' => 6.4550, 'longitude' => 3.3850])
        ->assertOk()->json('data');

    // A point inside only the broad area.
    $elsewhere = $this->withToken($merchant['token'])
        ->postJson("{$base}/zones/check", ['latitude' => 6.5200, 'longitude' => 3.3600])
        ->assertOk()->json('data');

    expect($inEstate['is_serviceable'])->toBeFalse()
        ->and($inEstate['zone']['name'])->toBe('Restricted estate')
        ->and($inEstate['zone']['is_restricted'])->toBeTrue()
        // Named so a customer can be told *why* — "outside our area" and "we
        // don't deliver to that estate" are different messages.
        ->and($elsewhere['is_serviceable'])->toBeTrue()
        ->and($elsewhere['zone']['name'])->toBe('Lagos mainland');
});

it('rejects a polygon that is not a shape', function (): void {
    $merchant = zoneMerchant($this, 'zone-bad@example.com');
    $base = "/api/v1/geo/merchants/vendor/{$merchant['vendorId']}";

    $this->withToken($merchant['token'])->postJson("{$base}/zones", [
        'name' => 'A line',
        'zone_type' => 'polygon',
        'polygon' => [[3.30, 6.50], [3.40, 6.50]],
    ])->assertStatus(422);

    $this->withToken($merchant['token'])->postJson("{$base}/zones", [
        'name' => 'Nonsense',
        'zone_type' => 'polygon',
        'polygon' => [[3.30, 6.50], [3.40, 6.50], ['x', 'y']],
    ])->assertStatus(422);
});

it('stops matching once a zone is deactivated', function (): void {
    $merchant = zoneMerchant($this, 'zone-off@example.com');
    $base = "/api/v1/geo/merchants/vendor/{$merchant['vendorId']}";

    $zone = $this->withToken($merchant['token'])->postJson("{$base}/zones", [
        'name' => 'Ikeja',
        'zone_type' => 'radius',
        'latitude' => 6.4550,
        'longitude' => 3.3841,
        'radius_metres' => 5_000,
    ])->assertCreated()->json('data');

    $this->withToken($merchant['token'])
        ->patchJson("{$base}/zones/{$zone['id']}", ['is_active' => false])
        ->assertOk();

    $this->withToken($merchant['token'])
        ->postJson("{$base}/zones/check", ['latitude' => 6.4560, 'longitude' => 3.3850])
        ->assertOk()
        ->assertJsonPath('data.is_serviceable', false);
});

it('refuses to let one merchant change another merchant\'s zone', function (): void {
    $mine = zoneMerchant($this, 'zone-mine@example.com');
    $theirs = zoneMerchant($this, 'zone-theirs@example.com');

    $zone = $this->withToken($mine['token'])
        ->postJson("/api/v1/geo/merchants/vendor/{$mine['vendorId']}/zones", [
            'name' => 'Mine',
            'zone_type' => 'radius',
            'latitude' => 6.4550,
            'longitude' => 3.3841,
            'radius_metres' => 5_000,
        ])->assertCreated()->json('data');

    $this->withToken($theirs['token'])
        ->patchJson("/api/v1/geo/merchants/vendor/{$theirs['vendorId']}/zones/{$zone['id']}", ['name' => 'Hijacked'])
        ->assertStatus(403);

    expect(DB::table('geo_delivery_zones')->where('id', $zone['id'])->value('name'))->toBe('Mine');
});

// ------------------------------------------------------------------- admin

it('refuses the command centre surfaces without the geo permission', function (string $path): void {
    $plain = zoneMerchant($this, 'admin-none-'.md5($path).'@example.com');

    $this->withToken($plain['token'])->getJson($path)->assertStatus(403);
})->with([
    '/api/v1/geo/admin/provider-health',
    '/api/v1/geo/admin/pricing-mode',
    '/api/v1/geo/admin/coverage',
]);

it('reports provider cost and health to an operator', function (): void {
    $admin = zoneMerchant($this, 'admin-health@example.com', [AdminRole::OperationsManager]);

    // Produce one billable call and one cache hit.
    $geocoding = app(GeocodingService::class);
    $geocoding->geocode(new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'));
    $geocoding->geocode(new GeocodeQuery('12 Allen Avenue, Ikeja', 'NG'));

    $health = $this->withToken($admin['token'])
        ->getJson('/api/v1/geo/admin/provider-health')
        ->assertOk()
        ->json('data');

    $geocode = collect($health['capabilities'])->firstWhere('capability', 'geocode');

    expect($geocode['total'])->toBe(2)
        ->and($geocode['billable'])->toBe(1)
        ->and($geocode['served_from_cache'])->toBe(1)
        ->and($geocode['cache_hit_rate'])->toBe(0.5)
        ->and($health['daily_quota']['billable_calls_today'])->toBe(1)
        ->and($health['daily_quota']['limit'])->toBeGreaterThan(0);
});

/**
 * The telemetry table is exported and graphed. It must not be a back door to
 * where customers live.
 */
it('records no coordinates or address text in the cost ledger', function (): void {
    app(GeocodingService::class)->geocode(new GeocodeQuery('14 Adeola Odeku Street, Victoria Island', 'NG'));

    $columns = array_keys((array) ProviderRequestModel::query()->first()->getAttributes());
    $serialised = json_encode(ProviderRequestModel::query()->get()->toArray());

    expect($columns)->not->toContain('latitude')
        ->and($columns)->not->toContain('longitude')
        ->and($columns)->not->toContain('address')
        ->and($columns)->not->toContain('query')
        // And nothing of the address leaked into a column that does exist.
        ->and($serialised)->not->toContain('Adeola')
        ->and($serialised)->not->toContain('Victoria Island');
});

it('reports the pricing mode so a quote can be explained', function (): void {
    $admin = zoneMerchant($this, 'admin-pricing@example.com', [AdminRole::OperationsManager]);

    $this->withToken($admin['token'])
        ->getJson('/api/v1/geo/admin/pricing-mode')
        ->assertOk()
        // The switch ships off, and the surface says so plainly.
        ->assertJsonPath('data.routed_pricing_enabled', false)
        ->assertJsonPath('data.refuse_when_unavailable', true);
});

it('reports geocoding coverage as a backlog rather than a statistic', function (): void {
    $admin = zoneMerchant($this, 'admin-coverage@example.com', [AdminRole::OperationsManager]);

    $coverage = $this->withToken($admin['token'])
        ->getJson('/api/v1/geo/admin/coverage')
        ->assertOk()
        ->json('data');

    expect($coverage['locations'])->toHaveKeys(['total', 'awaiting_geocode', 'confirmed', 'disputed'])
        // A count of reporting riders, never their positions.
        ->and($coverage['riders'])->toHaveKey('reporting_recently')
        ->and($coverage['riders'])->not->toHaveKey('locations');
});

/**
 * Correcting a location changes where riders are sent, so it is a narrower
 * power than reading a dashboard.
 */
it('separates reading geo health from correcting a location', function (): void {
    $reader = zoneMerchant($this, 'admin-reader@example.com', [AdminRole::VendorManager]);

    // VendorManager holds geo.read...
    $this->withToken($reader['token'])->getJson('/api/v1/geo/admin/coverage')->assertOk();

    // ...and not geo.manage.
    $this->withToken($reader['token'])
        ->postJson('/api/v1/geo/admin/locations/'.Str::orderedUuid().'/confirm')
        ->assertStatus(403);
});

it('lets an operator measure a journey and see where the number came from', function (): void {
    $admin = zoneMerchant($this, 'admin-measure@example.com', [AdminRole::OperationsManager]);

    $route = $this->withToken($admin['token'])
        ->postJson('/api/v1/geo/admin/measure', [
            'origin_latitude' => 6.6018,
            'origin_longitude' => 3.3515,
            'destination_latitude' => 6.4281,
            'destination_longitude' => 3.4219,
        ])
        ->assertOk()
        ->json('data.route');

    expect($route['distance_metres'])->toBeGreaterThan(0)
        // "The fee was wrong" and "the fee came from a six-hour-old route" are
        // different findings, and only one is a bug.
        ->and($route['source'])->toBe('provider')
        ->and($route['is_billable'])->toBeTrue()
        ->and($route)->toHaveKey('age_seconds');
});
