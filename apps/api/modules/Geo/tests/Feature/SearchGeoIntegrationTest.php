<?php

declare(strict_types=1);

use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Search\Domain\ValueObject\GeoPoint;
use EruoFood\Search\Infrastructure\Source\VendorSourceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M25 — Search reading the platform's canonical geography.
 *
 * Two forms of delegation, both closing a real inconsistency M25 found.
 *
 * **One distance formula.** Marketplace used an Earth radius of 6371.0 and
 * Search used 6371.0088, so the same journey measured differently depending on
 * which module was asked. Both now delegate to one implementation.
 *
 * **One source of coordinates.** Search indexed the latitude/longitude columns
 * that were written onto the vendor row once and never revisited. It now
 * prefers the canonical `geo_locations` record, which is the one a merchant
 * actually curates and a human can confirm.
 */
function indexableVendor(?string $locationId = null, ?float $legacyLat = null, ?float $legacyLng = null): string
{
    $vendorId = (string) Str::orderedUuid();

    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId,
        'owner_user_id' => (string) Str::orderedUuid(),
        'name' => 'Indexable Kitchen',
        'slug' => 'indexable-'.Str::lower(Str::random(8)),
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
        'latitude' => $legacyLat,
        'longitude' => $legacyLng,
        'primary_location_id' => $locationId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $vendorId;
}

function geoLocationRow(float $lat, float $lng, string $status = 'geocoded'): string
{
    $id = (string) Str::orderedUuid();

    DB::table('geo_locations')->insert([
        'id' => $id,
        'formatted_address' => '12 Allen Avenue, Ikeja, Lagos',
        'locality' => 'Lagos',
        'country_code' => 'NG',
        'latitude' => $lat,
        'longitude' => $lng,
        'source' => 'geocoded',
        'precision' => 'rooftop',
        'verification_status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

// ------------------------------------------------------- one distance formula

/**
 * The regression that closes M25's first finding. Immaterial as a distance;
 * a real inconsistency as a fact.
 */
it('gives Marketplace, Search and the canonical helper one answer', function (): void {
    $from = [6.6018, 3.3515];
    $to = [6.4281, 3.4219];

    expect((new GeoLocation(...$from))->distanceKmTo(new GeoLocation(...$to)))
        ->toBe((new GeoPoint(...$from))->distanceKmTo(new GeoPoint(...$to)))
        ->toBe(Haversine::kilometres(new Coordinates(...$from), new Coordinates(...$to)));
});

// ------------------------------------------------- one source of coordinates

it('indexes a vendor at its canonical location in preference to the legacy columns', function (): void {
    // The legacy columns say Victoria Island; the curated record says Ikeja.
    $locationId = geoLocationRow(6.6018, 3.3515);
    $vendorId = indexableVendor($locationId, legacyLat: 6.4281, legacyLng: 3.4219);

    $document = app(VendorSourceProvider::class)->fetch($vendorId);

    expect($document)->not->toBeNull()
        ->and($document->geo()->latitude)->toBe(6.6018)
        ->and($document->geo()->longitude)->toBe(3.3515);
});

it('falls back to the legacy columns when a vendor has no canonical location', function (): void {
    $vendorId = indexableVendor(legacyLat: 6.4281, legacyLng: 3.4219);

    $document = app(VendorSourceProvider::class)->fetch($vendorId);

    // Additive, not a breaking change: a vendor that predates M25 still indexes.
    expect($document->geo()->latitude)->toBe(6.4281);
});

/**
 * A search result placed in the wrong street is worse than one with no distance
 * at all, so an unusable canonical record is skipped rather than indexed.
 */
it('skips a disputed canonical location and uses the legacy point instead', function (): void {
    $locationId = geoLocationRow(6.6018, 3.3515, status: 'disputed');
    $vendorId = indexableVendor($locationId, legacyLat: 6.4281, legacyLng: 3.4219);

    $document = app(VendorSourceProvider::class)->fetch($vendorId);

    expect($document->geo()->latitude)->toBe(6.4281);
});

it('indexes no point at all when neither source has one', function (): void {
    $vendorId = indexableVendor();

    expect(app(VendorSourceProvider::class)->fetch($vendorId)->geo())->toBeNull();
});

it('skips an ungeocoded canonical location rather than indexing an empty point', function (): void {
    $id = (string) Str::orderedUuid();

    DB::table('geo_locations')->insert([
        'id' => $id,
        'address_text' => 'somewhere unresolved',
        'source' => 'manual',
        'precision' => 'unknown',
        'verification_status' => 'unverified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $vendorId = indexableVendor($id, legacyLat: 6.4281, legacyLng: 3.4219);

    expect(app(VendorSourceProvider::class)->fetch($vendorId)->geo()->latitude)->toBe(6.4281);
});
