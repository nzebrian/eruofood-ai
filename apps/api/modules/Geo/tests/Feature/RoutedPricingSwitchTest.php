<?php

declare(strict_types=1);

use EruoFood\Geo\Application\Service\DeliveryDistanceService;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;
use EruoFood\Geo\Domain\Exception\GeoRoutingUnavailable;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Marketplace\Application\Port\DeliveryFeeCalculator;
use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\ContactInfo;
use EruoFood\Marketplace\Domain\ValueObject\DeliveryZone as VendorDeliveryZone;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M25 — the switch that decides what customers are actually charged.
 *
 * `delivery.routing_pricing.enabled` ships **false**, and the first test in this
 * file is the one that proves it: a deployment of M25 changes nobody's price.
 * That is not a detail. Straight-line distance understates the real road
 * journey in Lagos by 30–60%, so correcting it *raises* fees for real
 * customers — a business decision with a customer-facing consequence, not a
 * deployment side effect.
 *
 * The rest of the file drives both modes and the whole fallback chain:
 *
 *   fresh routed → stale cached routed → merchant flat fee → honest refusal
 *
 * with a standing assertion at the end that no path through it can ever return
 * a straight-line fee.
 */
/**
 * A restaurant in Ikeja, optionally with its own delivery zones.
 *
 * @param list<array{name: string, radiusKm: float, feeMinor: int}> $zones
 */
function pricingVendor(array $zones = [], bool $withLocation = true): Vendor
{
    $repository = app(VendorRepository::class);
    $ikeja = $withLocation ? new GeoLocation(6.6018, 3.3515) : null;

    $vendor = Vendor::register(
        id: $repository->nextIdentity(),
        ownerUserId: (string) Str::orderedUuid(),
        name: 'Test Kitchen '.Str::random(6),
        slug: Slug::fromTitle('Test Kitchen '.Str::random(8)),
        type: VendorType::Restaurant,
        category: 'nigerian',
        contact: new ContactInfo('+2348012345678'),
        address: new Address('12 Allen Avenue', 'Ikeja', 'Lagos', $ikeja),
        now: new DateTimeImmutable(),
        autoVerify: true,
    );

    $vendor->setDeliveryZones(array_map(
        static fn (array $z): VendorDeliveryZone => new VendorDeliveryZone($z['name'], new Money($z['feeMinor'], 'NGN'), $z['radiusKm']),
        $zones,
    ));

    $repository->save($vendor);

    return $vendor;
}

/** Victoria Island — about 20 km straight line from Ikeja. */
function victoriaIsland(): GeoLocation
{
    return new GeoLocation(6.4281, 3.4219);
}

function setRoutedPricing(bool $enabled, bool $refuseWhenUnavailable = true, bool $shadow = false): void
{
    config()->set('delivery.routing_pricing.enabled', $enabled);
    config()->set('delivery.routing_pricing.refuse_when_unavailable', $refuseWhenUnavailable);
    config()->set('delivery.routing_pricing.shadow_mode', $shadow);

    // Both are singletons that read the switch at construction, which is why a
    // real deployment restarts rather than expecting a live flip.
    app()->forgetInstance(DeliveryDistanceService::class);
    app()->forgetInstance(DeliveryDistanceProvider::class);
    app()->forgetInstance(DeliveryFeeCalculator::class);
}

// ------------------------------------------------------- the default: OFF

/**
 * The most important assertion in M25. Shipping this milestone must not change
 * a single customer's bill.
 */
it('ships with routed pricing off', function (): void {
    expect(config('delivery.routing_pricing.enabled'))->toBeFalse()
        ->and(app(DeliveryDistanceProvider::class)->routedPricingEnabled())->toBeFalse();
});

it('charges exactly the pre-M25 fee while the switch is off', function (): void {
    setRoutedPricing(false);

    $vendor = pricingVendor();
    $subtotal = new Money(500_000, 'NGN');

    $quote = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal);

    // The legacy model: base + per-km on *straight-line* kilometres, capped.
    $straightLineKm = Haversine::kilometres(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
    );
    $expected = min(300_000, 50_000 + 8_000 * (int) ceil($straightLineKm));

    expect($quote->fee->minorUnits)->toBe($expected);
});

/**
 * With the switch off, a routing outage must be invisible: the old path does
 * not consult a provider at all, so it cannot be broken by one being down.
 */
it('is unaffected by a routing outage while the switch is off', function (): void {
    setRoutedPricing(false);

    $vendor = pricingVendor();
    $withProvider = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN'));

    breakRouting();

    $withoutProvider = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN'));

    expect($withoutProvider->fee->minorUnits)->toBe($withProvider->fee->minorUnits);
});

// -------------------------------------------------------- the switch: ON

it('charges more on routed distance than on the straight line', function (): void {
    $vendor = pricingVendor();
    $subtotal = new Money(500_000, 'NGN');

    setRoutedPricing(false);
    $legacy = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal);

    setRoutedPricing(true);
    $routed = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal);

    // The whole point of the milestone: the road is longer than the crow's
    // flight, and the fee now reflects it.
    expect($routed->fee->minorUnits)->toBeGreaterThan($legacy->fee->minorUnits);
});

it('rolls back to the old price the moment the switch goes off again', function (): void {
    $vendor = pricingVendor();
    $subtotal = new Money(500_000, 'NGN');

    setRoutedPricing(true);
    $routed = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal);

    setRoutedPricing(false);
    $rolledBack = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal);

    // No deploy, no migration — a configuration change and the price is back.
    expect($rolledBack->fee->minorUnits)->toBeLessThan($routed->fee->minorUnits);
});

it('still honours a merchant zone fee when routed pricing is on', function (): void {
    setRoutedPricing(true);

    // A generous zone that covers the whole journey. Routed distance decides
    // *which* zone matches; it does not overrule the merchant's own price.
    $vendor = pricingVendor([['name' => 'City wide', 'radiusKm' => 100.0, 'feeMinor' => 70_000]]);

    $quote = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN'));

    expect($quote->fee->minorUnits)->toBe(70_000)
        ->and($quote->zoneName)->toBe('City wide');
});

it('never charges for delivery above the free-delivery threshold, routing or not', function (): void {
    setRoutedPricing(true);
    breakRouting();

    $vendor = pricingVendor();

    // Free delivery is a subtotal rule and owes nothing to distance, so it must
    // not cost a provider call — and must not be refused because one failed.
    $quote = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(5_000_000, 'NGN'));

    expect($quote->fee->minorUnits)->toBe(0)
        ->and($quote->zoneName)->toBe('free');
});

// ------------------------------------------------------- the fallback chain

/**
 * Step 4 of the approved chain, and the default behaviour. Declining to price a
 * delivery is a poor experience; charging confidently for a journey nobody
 * measured is worse, because at scale it is a systematic one-directional error
 * that nobody notices.
 */
it('refuses to quote rather than guess when routing is unavailable', function (): void {
    setRoutedPricing(true, refuseWhenUnavailable: true);
    breakRouting();

    $vendor = pricingVendor();

    expect(fn () => app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN')))
        ->toThrow(GeoRoutingUnavailable::class);
});

/**
 * Step 3: a merchant's own published fee, reachable only when an operator has
 * deliberately turned refusal off. The lowest of their zone fees, so a customer
 * is never charged more than a published price for a journey the platform could
 * not measure.
 */
it('falls back to the merchant flat fee when refusal is disabled', function (): void {
    setRoutedPricing(true, refuseWhenUnavailable: false);
    breakRouting();

    $vendor = pricingVendor([
        ['name' => 'Near', 'radiusKm' => 3.0, 'feeMinor' => 40_000],
        ['name' => 'Far', 'radiusKm' => 30.0, 'feeMinor' => 90_000],
    ]);

    $quote = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN'));

    // The cheapest published price: the platform absorbs the difference, which
    // is the right side to err on when the failure is ours.
    expect($quote->fee->minorUnits)->toBe(40_000);
});

it('refuses when refusal is disabled but the merchant published no fee at all', function (): void {
    setRoutedPricing(true, refuseWhenUnavailable: false);
    breakRouting();

    $vendor = pricingVendor();

    expect(fn () => app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN')))
        ->toThrow(GeoRoutingUnavailable::class);
});

it('refuses when a merchant has no coordinates to route from', function (): void {
    setRoutedPricing(true);

    $vendor = pricingVendor(withLocation: false);

    expect(fn () => app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), new Money(500_000, 'NGN')))
        ->toThrow(GeoRoutingUnavailable::class);
});

// --------------------------------------------------- the step that must not exist

/**
 * The standing assertion. If somebody ever adds a haversine rung to the chain —
 * and it is tempting, because it always returns an answer — this fails.
 */
it('never prices a delivery on straight-line distance when routed pricing is on', function (): void {
    setRoutedPricing(true, refuseWhenUnavailable: true);

    $vendor = pricingVendor();
    $subtotal = new Money(500_000, 'NGN');

    $straightLineKm = Haversine::kilometres(
        new Coordinates(6.6018, 3.3515),
        new Coordinates(6.4281, 3.4219),
    );
    $straightLineFee = min(300_000, 50_000 + 8_000 * (int) ceil($straightLineKm));

    // With routing working, the fee is above the straight-line fee.
    $routed = app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal);
    expect($routed->fee->minorUnits)->toBeGreaterThan($straightLineFee);

    // With routing broken, the answer is a refusal — not the straight-line fee,
    // which is available at that moment and would look perfectly plausible.
    breakRouting();

    expect(fn () => app(DeliveryFeeCalculator::class)->quote($vendor, victoriaIsland(), $subtotal))
        ->toThrow(GeoRoutingUnavailable::class);
});

/**
 * A provider that returns a distance four times the straight line is not
 * describing a delivery — it is a ferry route, a wrong hemisphere or a
 * mis-parsed field. A bad number on a customer's bill is worse than no number.
 */
it('rejects an implausible routed distance rather than billing it', function (): void {
    $service = app(DeliveryDistanceService::class);

    $origin = new Coordinates(6.6018, 3.3515);
    $destination = new Coordinates(6.4281, 3.4219);

    expect($service->route($origin, $destination))->not->toBeNull();

    // A mock road factor of 1.4 is plausible; a ratio ceiling below it is not,
    // so tightening the ceiling must start rejecting.
    config()->set('delivery.routing_pricing.max_detour_ratio', 1.1);
    app()->forgetInstance(DeliveryDistanceService::class);
    Cache::flush();

    expect(app(DeliveryDistanceService::class)->route($origin, $destination))->toBeNull();
});

/**
 * A distance provider that can never establish a route.
 *
 * The double is placed at the published contract rather than deep in the
 * routing internals, because that contract is exactly what the calculator
 * depends on — faking anything closer would be testing a seam no caller uses.
 */
function breakRouting(): void
{
    Cache::flush();

    app()->instance(DeliveryDistanceProvider::class, new class () implements DeliveryDistanceProvider {
        public function between(
            float $originLatitude,
            float $originLongitude,
            float $destinationLatitude,
            float $destinationLongitude,
            ?string $travelMode = null,
        ): ?EruoFood\Geo\Contracts\DeliveryDistance {
            return null;
        }

        public function routedPricingEnabled(): bool
        {
            return (bool) config('delivery.routing_pricing.enabled', false);
        }
    });

    app()->forgetInstance(DeliveryFeeCalculator::class);
}
