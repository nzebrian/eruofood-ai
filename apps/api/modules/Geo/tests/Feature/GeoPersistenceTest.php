<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\Address\CustomerAddress;
use EruoFood\Geo\Domain\Address\CustomerAddressRepository;
use EruoFood\Geo\Domain\Enum\AddressLabel;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Enum\LocationVerificationStatus;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Location\Location;
use EruoFood\Geo\Domain\Location\LocationRepository;
use EruoFood\Geo\Domain\Rider\RiderLocation;
use EruoFood\Geo\Domain\Rider\RiderLocationRepository;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\Route\RouteCacheRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;
use EruoFood\Geo\Domain\Zone\DeliveryZone;
use EruoFood\Geo\Domain\Zone\DeliveryZoneRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentRouteCacheRepository;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\RiderLocationModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M25 — the Geo repositories against a real database.
 *
 * Round-tripping is the least of it. What these assert is that the two-stage
 * proximity query returns the same set an exact measurement would, that a
 * stored route comes back badged as cache rather than as a fresh provider
 * result, and that the coordinate range constraints hold at the engine rather
 * than only in PHP.
 *
 * The CHECK constraints are PostgreSQL objects with no SQLite equivalent —
 * SQLite cannot add them via ALTER TABLE. Those tests say so and skip, rather
 * than asserting a protection the test engine does not actually provide.
 */
function geoPgOnly(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

function geoNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-13 10:00:00');
}

function saveLocationAt(float $lat, float $lon, ?string $label = null): string
{
    $repository = app(LocationRepository::class);
    $id = $repository->nextIdentity();

    $location = Location::fromAddress(
        $id,
        new PostalAddress(formatted: $label ?? sprintf('Point %F,%F', $lat, $lon), locality: 'Lagos', countryCode: 'NG'),
        geoNow(),
    );

    $location->applyGeocode(
        new Coordinates($lat, $lon),
        new PostalAddress(formatted: $label ?? 'Geocoded point', locality: 'Lagos', countryCode: 'NG'),
        LocationPrecision::Rooftop,
        'mock',
        'mock_place_1',
        geoNow(),
    );

    $repository->save($location);

    return $id;
}

// ---------------------------------------------------------------- locations

it('round-trips a location through the database without losing anything', function (): void {
    $repository = app(LocationRepository::class);
    $id = $repository->nextIdentity();

    $location = Location::fromAddress(
        $id,
        new PostalAddress(formatted: '12 Allen Avenue, Ikeja', line1: '12 Allen Avenue', locality: 'Lagos', adminArea: 'Lagos', countryCode: 'NG'),
        geoNow(),
    );
    $repository->save($location);

    $loaded = $repository->findById($id);

    expect($loaded)->not->toBeNull()
        ->and($loaded->address()->line1)->toBe('12 Allen Avenue')
        ->and($loaded->address()->countryCode)->toBe('NG')
        ->and($loaded->coordinates())->toBeNull()
        ->and($loaded->status())->toBe(LocationVerificationStatus::Unverified)
        ->and($loaded->needsGeocoding())->toBeTrue();
});

it('persists a geocode and its precision together', function (): void {
    $repository = app(LocationRepository::class);
    $id = saveLocationAt(6.4550, 3.3841, '12 Allen Avenue');

    $loaded = $repository->findById($id);

    expect($loaded->coordinates()->latitude)->toBe(6.455)
        ->and($loaded->coordinates()->longitude)->toBe(3.3841)
        ->and($loaded->precision())->toBe(LocationPrecision::Rooftop)
        ->and($loaded->source())->toBe(LocationSource::Geocoded)
        ->and($loaded->status())->toBe(LocationVerificationStatus::Geocoded)
        ->and($loaded->provider())->toBe('mock')
        ->and($loaded->isDeliverable())->toBeTrue();
});

/**
 * The customer's own words survive the provider's rewrite. Losing them would
 * leave nobody able to see what a merchant had actually meant when their
 * address geocoded to the wrong street.
 */
it('keeps the originally entered address alongside the provider version', function (): void {
    $repository = app(LocationRepository::class);
    $id = $repository->nextIdentity();

    $location = Location::fromAddress($id, new PostalAddress(formatted: 'blue gate opposite the mosque'), geoNow());
    $repository->save($location);

    $location->applyGeocode(
        new Coordinates(6.4550, 3.3841),
        new PostalAddress(formatted: '14 Allen Avenue, Ikeja, Lagos, Nigeria'),
        LocationPrecision::Rooftop,
        'mock',
        null,
        geoNow(),
    );
    $repository->save($location);

    $row = DB::table('geo_locations')->where('id', $id)->first();

    expect($row->address_text)->toBe('blue gate opposite the mosque')
        ->and($row->formatted_address)->toBe('14 Allen Avenue, Ikeja, Lagos, Nigeria');
});

it('finds locations within a radius, nearest first, and excludes the rest', function (): void {
    $centre = new Coordinates(6.4550, 3.3841);

    $near = saveLocationAt(6.4560, 3.3850);    // ~140 m
    $middle = saveLocationAt(6.4650, 3.3900);  // ~1.2 km
    $far = saveLocationAt(6.4281, 3.4219);     // ~5 km

    $results = app(LocationRepository::class)->withinRadius($centre, 2_000.0);

    expect($results)->toHaveCount(2)
        ->and($results[0]['location']->id())->toBe($near)
        ->and($results[1]['location']->id())->toBe($middle)
        ->and($results[0]['distanceMetres'])->toBeLessThan($results[1]['distanceMetres'])
        ->and(array_column(array_map(
            static fn (array $r): array => ['id' => $r['location']->id()],
            $results,
        ), 'id'))->not->toContain($far);
});

/**
 * The bounding box is a rectangle and the radius is a circle, so a point near a
 * corner of the box sits outside the circle. If the exact pass were skipped it
 * would be returned anyway — the classic silent over-match in this pattern.
 */
it('excludes a point inside the bounding box but outside the circle', function (): void {
    $centre = new Coordinates(6.4550, 3.3841);

    // Diagonally offset by ~1 km on each axis: ~1.4 km away, inside a 1 km box,
    // outside a 1 km circle.
    $corner = saveLocationAt(6.4640, 3.3931);

    $withinCircle = app(LocationRepository::class)->withinRadius($centre, 1_000.0);
    $wider = app(LocationRepository::class)->withinRadius($centre, 2_000.0);

    expect($withinCircle)->toBeEmpty()
        ->and($wider)->toHaveCount(1)
        ->and($wider[0]['location']->id())->toBe($corner);
});

it('honours the result limit after measuring, not before', function (): void {
    saveLocationAt(6.4551, 3.3842);
    saveLocationAt(6.4552, 3.3843);
    saveLocationAt(6.4553, 3.3844);

    $results = app(LocationRepository::class)->withinRadius(new Coordinates(6.4550, 3.3841), 5_000.0, limit: 2);

    expect($results)->toHaveCount(2)
        // Still the two nearest, not an arbitrary two.
        ->and($results[0]['distanceMetres'])->toBeLessThan($results[1]['distanceMetres']);
});

it('lists locations still awaiting a geocode', function (): void {
    $repository = app(LocationRepository::class);
    $pending = $repository->nextIdentity();

    $repository->save(Location::fromAddress($pending, new PostalAddress(formatted: 'somewhere unresolved'), geoNow()));
    saveLocationAt(6.4550, 3.3841);

    $waiting = $repository->needingGeocode();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]->id())->toBe($pending);
});

it('does not downgrade a confirmed location when it is geocoded again', function (): void {
    $repository = app(LocationRepository::class);
    $id = saveLocationAt(6.4550, 3.3841);

    $location = $repository->findById($id);
    $location->confirm('operator-1', geoNow());
    $repository->save($location);

    $location = $repository->findById($id);
    $location->applyGeocode(
        new Coordinates(6.4551, 3.3842),
        new PostalAddress(formatted: 'Re-geocoded'),
        LocationPrecision::Approximate,
        'mock',
        null,
        geoNow(),
    );
    $repository->save($location);

    expect($repository->findById($id)->status())->toBe(LocationVerificationStatus::Confirmed);
});

// --------------------------------------------------------- customer addresses

it('round-trips a customer address and scopes reads to its owner', function (): void {
    $repository = app(CustomerAddressRepository::class);
    $mine = (string) Str::orderedUuid();
    $theirs = (string) Str::orderedUuid();

    $repository->save(CustomerAddress::create(
        $repository->nextIdentity(),
        $mine,
        saveLocationAt(6.4550, 3.3841),
        AddressLabel::Home,
        geoNow(),
        deliveryInstructions: 'blue gate, ask for Musa',
    ));
    $repository->save(CustomerAddress::create(
        $repository->nextIdentity(),
        $theirs,
        saveLocationAt(6.4281, 3.4219),
        AddressLabel::Work,
        geoNow(),
    ));

    $listed = $repository->forUser($mine);

    expect($listed)->toHaveCount(1)
        ->and($listed[0]->deliveryInstructions())->toBe('blue gate, ask for Musa')
        ->and($listed[0]->belongsTo($mine))->toBeTrue()
        ->and($listed[0]->belongsTo($theirs))->toBeFalse()
        ->and($repository->countActiveFor($mine))->toBe(1);
});

it('keeps exactly one default address per customer', function (): void {
    $repository = app(CustomerAddressRepository::class);
    $userId = (string) Str::orderedUuid();

    $first = CustomerAddress::create($repository->nextIdentity(), $userId, saveLocationAt(6.4550, 3.3841), AddressLabel::Home, geoNow(), isDefault: true);
    $repository->save($first);

    $second = CustomerAddress::create($repository->nextIdentity(), $userId, saveLocationAt(6.4281, 3.4219), AddressLabel::Work, geoNow());
    $repository->save($second);

    $repository->clearDefaultFor($userId, exceptId: $second->id());
    $second->makeDefault(geoNow());
    $repository->save($second);

    expect($repository->defaultFor($userId)->id())->toBe($second->id())
        ->and(array_filter($repository->forUser($userId), static fn (CustomerAddress $a): bool => $a->isDefault()))
        ->toHaveCount(1);
});

it('hides a deactivated address from the list but keeps the row for past orders', function (): void {
    $repository = app(CustomerAddressRepository::class);
    $userId = (string) Str::orderedUuid();

    $address = CustomerAddress::create($repository->nextIdentity(), $userId, saveLocationAt(6.4550, 3.3841), AddressLabel::Home, geoNow(), isDefault: true);
    $repository->save($address);

    $address->deactivate(geoNow());
    $repository->save($address);

    expect($repository->forUser($userId))->toBeEmpty()
        ->and($repository->countActiveFor($userId))->toBe(0)
        ->and($repository->defaultFor($userId))->toBeNull()
        // The row survives, so an order that went there can still be explained.
        ->and($repository->findById($address->id()))->not->toBeNull()
        ->and($repository->forUser($userId, activeOnly: false))->toHaveCount(1);
});

it('lists the default address first and the never-used ones last', function (): void {
    $repository = app(CustomerAddressRepository::class);
    $userId = (string) Str::orderedUuid();

    $unused = CustomerAddress::create($repository->nextIdentity(), $userId, saveLocationAt(6.4550, 3.3841), AddressLabel::Home, geoNow());
    $repository->save($unused);

    $used = CustomerAddress::create($repository->nextIdentity(), $userId, saveLocationAt(6.4281, 3.4219), AddressLabel::Work, geoNow());
    $used->markUsed(geoNow());
    $repository->save($used);

    $preferred = CustomerAddress::create($repository->nextIdentity(), $userId, saveLocationAt(6.5000, 3.3600), AddressLabel::Other, geoNow(), customName: 'Mum', isDefault: true);
    $repository->save($preferred);

    $listed = array_map(static fn (CustomerAddress $a): string => $a->id(), $repository->forUser($userId));

    expect($listed)->toBe([$preferred->id(), $used->id(), $unused->id()]);
});

// ------------------------------------------------------------ rider locations

it('keeps one row per rider, overwritten in place rather than appended', function (): void {
    $repository = app(RiderLocationRepository::class);
    $riderId = (string) Str::orderedUuid();
    $userId = (string) Str::orderedUuid();

    $repository->save(RiderLocation::report($riderId, $userId, new Coordinates(6.4550, 3.3841), geoNow()));
    $repository->save(RiderLocation::report($riderId, $userId, new Coordinates(6.4600, 3.3900), geoNow()->modify('+1 minute')));

    expect(RiderLocationModel::query()->count())->toBe(1)
        ->and($repository->findByRider($riderId)->coordinates()->latitude)->toBe(6.46);
});

it('excludes a stale rider from a proximity search', function (): void {
    $repository = app(RiderLocationRepository::class);
    $now = geoNow();

    $fresh = (string) Str::orderedUuid();
    $stale = (string) Str::orderedUuid();

    $repository->save(RiderLocation::report($fresh, (string) Str::orderedUuid(), new Coordinates(6.4560, 3.3850), $now));
    $repository->save(RiderLocation::report($stale, (string) Str::orderedUuid(), new Coordinates(6.4555, 3.3845), $now->modify('-2 hours')));

    $nearby = $repository->nearby(new Coordinates(6.4550, 3.3841), 5_000.0, $now->modify('-5 minutes'));

    expect($nearby)->toHaveCount(1)
        ->and($nearby[0]['location']->riderId())->toBe($fresh)
        // The stale rider is the nearer of the two, so this cannot pass by
        // accident of ordering.
        ->and($repository->countFreshSince($now->modify('-5 minutes')))->toBe(1);
});

it('forgets a rider position entirely when they go offline', function (): void {
    $repository = app(RiderLocationRepository::class);
    $riderId = (string) Str::orderedUuid();

    $repository->save(RiderLocation::report($riderId, (string) Str::orderedUuid(), new Coordinates(6.4550, 3.3841), geoNow()));
    $repository->forget($riderId);

    expect($repository->findByRider($riderId))->toBeNull()
        ->and(RiderLocationModel::query()->count())->toBe(0);
});

it('carries accuracy through persistence so callers can judge a fix', function (): void {
    $repository = app(RiderLocationRepository::class);
    $riderId = (string) Str::orderedUuid();

    $repository->save(RiderLocation::report(
        $riderId,
        (string) Str::orderedUuid(),
        new Coordinates(6.4550, 3.3841),
        geoNow(),
        accuracyMetres: 2_000.0,
        headingDegrees: 91.5,
        speedMps: 8.25,
    ));

    $loaded = $repository->findByRider($riderId);

    expect($loaded->accuracyMetres())->toBe(2_000.0)
        ->and($loaded->headingDegrees())->toBe(91.5)
        ->and($loaded->speedMps())->toBe(8.25)
        ->and($loaded->isPreciseEnough())->toBeFalse();
});

// -------------------------------------------------------------- delivery zones

it('round-trips a radius zone and its derived bounding box', function (): void {
    $repository = app(DeliveryZoneRepository::class);
    $id = $repository->nextIdentity();
    $vendorId = (string) Str::orderedUuid();

    $repository->save(DeliveryZone::radius($id, 'vendor', $vendorId, 'Ikeja 5km', new Coordinates(6.4550, 3.3841), 5_000, geoNow(), feeMinor: 50_000));

    $loaded = $repository->findById($id);
    $row = DB::table('geo_delivery_zones')->where('id', $id)->first();

    expect($loaded->radiusMetres())->toBe(5_000)
        ->and($loaded->centre()->latitude)->toBe(6.455)
        ->and($loaded->feeMinor())->toBe(50_000)
        // Derived on save, never entered, so it cannot drift from the shape.
        ->and((float) $row->bbox_min_lat)->toBeLessThan(6.455)
        ->and((float) $row->bbox_max_lat)->toBeGreaterThan(6.455);
});

it('round-trips a polygon zone with its ring intact and in GeoJSON order', function (): void {
    $repository = app(DeliveryZoneRepository::class);
    $id = $repository->nextIdentity();
    $vendorId = (string) Str::orderedUuid();

    $repository->save(DeliveryZone::polygon($id, 'vendor', $vendorId, 'Ikeja box', [
        [3.30, 6.50], [3.40, 6.50], [3.40, 6.60], [3.30, 6.60],
    ], geoNow()));

    $loaded = $repository->findById($id);

    expect($loaded->polygonPoints())->toBe([[3.30, 6.50], [3.40, 6.50], [3.40, 6.60], [3.30, 6.60]])
        ->and($loaded->contains(new Coordinates(6.55, 3.35)))->toBeTrue()
        ->and($loaded->contains(new Coordinates(6.45, 3.35)))->toBeFalse();
});

/**
 * The prefilter must be generous and the ordering must put the specific
 * exclusion first, or a restricted area inside a service area never gets
 * consulted and the platform promises a delivery it will not make.
 */
it('returns box candidates ordered so a specific exclusion is consulted first', function (): void {
    $repository = app(DeliveryZoneRepository::class);
    $point = new Coordinates(6.4550, 3.3841);

    $broad = $repository->nextIdentity();
    $repository->save(DeliveryZone::radius($broad, 'platform', null, 'Lagos mainland', $point, 20_000, geoNow(), priority: 100));

    $exclusion = $repository->nextIdentity();
    $repository->save(DeliveryZone::polygon($exclusion, 'platform', null, 'Restricted estate', [
        [3.3800, 6.4500], [3.3900, 6.4500], [3.3900, 6.4600], [3.3800, 6.4600],
    ], geoNow(), isRestricted: true, priority: 10));

    $elsewhere = $repository->nextIdentity();
    $repository->save(DeliveryZone::radius($elsewhere, 'platform', null, 'Abuja', new Coordinates(9.0765, 7.3986), 5_000, geoNow()));

    $candidates = $repository->candidatesFor($point);

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->id())->toBe($exclusion)
        ->and($candidates[0]->isRestricted())->toBeTrue()
        ->and($candidates[1]->id())->toBe($broad)
        ->and(array_map(static fn (DeliveryZone $z): string => $z->id(), $candidates))->not->toContain($elsewhere);
});

it('excludes a deactivated zone from candidates', function (): void {
    $repository = app(DeliveryZoneRepository::class);
    $id = $repository->nextIdentity();
    $vendorId = (string) Str::orderedUuid();

    $zone = DeliveryZone::radius($id, 'vendor', $vendorId, 'Ikeja', new Coordinates(6.4550, 3.3841), 5_000, geoNow());
    $repository->save($zone);

    expect($repository->candidatesFor(new Coordinates(6.4560, 3.3850)))->toHaveCount(1);

    $zone->deactivate(geoNow());
    $repository->save($zone);

    expect($repository->candidatesFor(new Coordinates(6.4560, 3.3850)))->toBeEmpty();
});

it('separates a platform zone with no owner from a vendor zone', function (): void {
    $repository = app(DeliveryZoneRepository::class);
    $vendorId = (string) Str::orderedUuid();

    $platform = $repository->nextIdentity();
    $repository->save(DeliveryZone::radius($platform, 'platform', null, 'Lagos', new Coordinates(6.4550, 3.3841), 20_000, geoNow()));

    $vendor = $repository->nextIdentity();
    $repository->save(DeliveryZone::radius($vendor, 'vendor', $vendorId, 'Ikeja', new Coordinates(6.4550, 3.3841), 5_000, geoNow()));

    expect(array_map(static fn (DeliveryZone $z): string => $z->id(), $repository->forOwner('platform', null)))->toBe([$platform])
        ->and(array_map(static fn (DeliveryZone $z): string => $z->id(), $repository->forOwner('vendor', $vendorId)))->toBe([$vendor]);
});

// ----------------------------------------------------------------- route cache

function storedRoute(DateTimeImmutable $calculatedAt): Route
{
    return new Route(
        origin: new Coordinates(6.6018, 3.3515),
        destination: new Coordinates(6.4281, 3.4219),
        distanceMetres: 26_000,
        durationSeconds: 3_900,
        travelMode: TravelMode::TwoWheeler,
        source: RouteSource::Provider,
        provider: 'mock',
        calculatedAt: $calculatedAt,
        providerRouteId: 'mock_route_1',
    );
}

/**
 * A stored route is evidence of a past measurement, and pricing has to be able
 * to tell that apart from a call made a second ago. If it came back badged
 * `Provider` it would bypass the grace period entirely and a week-old distance
 * would bill as a fresh one.
 */
it('badges everything read back from the table as cache, never as fresh', function (): void {
    $repository = app(RouteCacheRepository::class);
    // Relative to now, not a wall-clock date: an absolute timestamp would drift
    // past the grace period as the day wore on and fail only after mid-afternoon.
    $calculatedAt = new DateTimeImmutable('-1 hour');

    $repository->store('key-1', storedRoute($calculatedAt));

    $loaded = $repository->findByKey('key-1');

    expect($loaded->source)->toBe(RouteSource::Cache)
        ->and($loaded->distanceMetres)->toBe(26_000)
        ->and($loaded->durationSeconds)->toBe(3_900)
        ->and($loaded->travelMode)->toBe(TravelMode::TwoWheeler)
        ->and($loaded->provider)->toBe('mock')
        ->and($loaded->calculatedAt->format('Y-m-d H:i:s'))->toBe($calculatedAt->format('Y-m-d H:i:s'));
});

it('overwrites the entry for a key instead of accumulating rows', function (): void {
    $repository = app(RouteCacheRepository::class);

    $repository->store('key-1', storedRoute(new DateTimeImmutable('-2 hours')));
    $repository->store('key-1', new Route(
        origin: new Coordinates(6.6018, 3.3515),
        destination: new Coordinates(6.4281, 3.4219),
        distanceMetres: 27_500,
        durationSeconds: 4_200,
        travelMode: TravelMode::TwoWheeler,
        source: RouteSource::Provider,
        provider: 'mock',
        calculatedAt: new DateTimeImmutable('-1 hour'),
    ));

    expect(DB::table('geo_route_cache')->count())->toBe(1)
        ->and($repository->findByKey('key-1')->distanceMetres)->toBe(27_500);
});

it('withholds a route past the grace period from the hot path but not from a deliberate look', function (): void {
    // A one-hour grace, and a route calculated two hours ago.
    $repository = new EloquentRouteCacheRepository(staleGraceSeconds: 3_600);
    $repository->store('key-1', storedRoute(new DateTimeImmutable('-2 hours')));

    expect($repository->findByKey('key-1'))->toBeNull()
        ->and($repository->findByKeyRegardlessOfAge('key-1'))->not->toBeNull();
});

it('purges routes older than a cutoff and leaves newer ones', function (): void {
    $repository = app(RouteCacheRepository::class);

    $repository->store('old', storedRoute(new DateTimeImmutable('2026-08-01 09:00:00')));
    $repository->store('new', storedRoute(new DateTimeImmutable('2026-08-13 09:00:00')));

    $deleted = $repository->purgeOlderThan(new DateTimeImmutable('2026-08-10 00:00:00'));

    expect($deleted)->toBe(1)
        ->and($repository->findByKeyRegardlessOfAge('old'))->toBeNull()
        ->and($repository->findByKeyRegardlessOfAge('new'))->not->toBeNull();
});

// ------------------------------------------------------- database constraints

/**
 * The domain rejects impossible coordinates at construction, but M24 taught
 * that an application-only guarantee is not a guarantee: a raw insert, a
 * backfill or a future service bypasses it entirely.
 */
it('rejects an impossible latitude at the database', function (): void {
    expect(fn () => DB::table('geo_locations')->insert([
        'id' => (string) Str::orderedUuid(),
        'latitude' => 95.0,
        'longitude' => 3.3841,
        'source' => 'manual',
        'precision' => 'unknown',
        'verification_status' => 'unverified',
        'created_at' => geoNow(),
        'updated_at' => geoNow(),
    ]))->toThrow(QueryException::class, 'geo_locations_latitude_range');
})->skip(fn (): bool => ! geoPgOnly(), 'CHECK constraints are a PostgreSQL guarantee; SQLite cannot add them via ALTER TABLE.');

it('rejects half a coordinate at the database', function (): void {
    expect(fn () => DB::table('geo_locations')->insert([
        'id' => (string) Str::orderedUuid(),
        'latitude' => 6.4550,
        'longitude' => null,
        'source' => 'manual',
        'precision' => 'unknown',
        'verification_status' => 'unverified',
        'created_at' => geoNow(),
        'updated_at' => geoNow(),
    ]))->toThrow(QueryException::class, 'geo_locations_coordinates_paired');
})->skip(fn (): bool => ! geoPgOnly(), 'CHECK constraints are a PostgreSQL guarantee; SQLite cannot add them via ALTER TABLE.');

it('rejects a zone that claims a shape it does not have', function (): void {
    expect(fn () => DB::table('geo_delivery_zones')->insert([
        'id' => (string) Str::orderedUuid(),
        'owner_type' => 'vendor',
        'owner_id' => (string) Str::orderedUuid(),
        'name' => 'Radius with no centre',
        'zone_type' => 'radius',
        'is_restricted' => false,
        'is_active' => true,
        'priority' => 100,
        'created_at' => geoNow(),
        'updated_at' => geoNow(),
    ]))->toThrow(QueryException::class, 'geo_delivery_zones_shape_present');
})->skip(fn (): bool => ! geoPgOnly(), 'CHECK constraints are a PostgreSQL guarantee; SQLite cannot add them via ALTER TABLE.');

it('accepts a valid Lagos point, so the constraints are not simply rejecting everything', function (): void {
    $id = saveLocationAt(6.4550, 3.3841);

    expect(app(LocationRepository::class)->findById($id)->coordinates()->latitude)->toBe(6.455);
});
