<?php

declare(strict_types=1);

use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\ContactInfo;
use EruoFood\Marketplace\Domain\ValueObject\DeliveryZone;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Infrastructure\Delivery\ZoneDeliveryFeeCalculator;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Shared\Domain\ValueObject\Slug;

function vendorAt(GeoLocation $geo): Vendor
{
    return Vendor::register(
        'v1',
        'owner',
        'Test Vendor',
        Slug::fromTitle('Test Vendor'),
        VendorType::Restaurant,
        'african',
        new ContactInfo('+2348000000000'),
        new Address('1 St', 'Lagos', 'Lagos', $geo),
        new DateTimeImmutable(),
        autoVerify: true,
    );
}

function calc(): ZoneDeliveryFeeCalculator
{
    // base ₦500, ₦80/km, cap ₦3000, free over ₦10,000
    return new ZoneDeliveryFeeCalculator(50000, 8000, 300000, 1000000, 'NGN');
}

it('gives free delivery above the threshold', function (): void {
    $quote = calc()->quote(vendorAt(new GeoLocation(6.45, 3.38)), new GeoLocation(6.46, 3.39), new Money(1000000, 'NGN'));
    expect($quote->fee->minorUnits)->toBe(0)->and($quote->zoneName)->toBe('free');
});

it('charges a distance-based fee when no zone matches', function (): void {
    $origin = new GeoLocation(6.4550, 3.3841);
    $dropoff = new GeoLocation(6.5244, 3.3792); // ~7.7 km north
    $quote = calc()->quote(vendorAt($origin), $dropoff, new Money(300000, 'NGN'));

    // base 50000 + 80000*ceil(~7.7) = 50000 + 8*8000 = 114000, under the cap.
    expect($quote->fee->minorUnits)->toBeGreaterThan(50000)
        ->and($quote->fee->minorUnits)->toBeLessThanOrEqual(300000)
        ->and($quote->zoneName)->toBeNull();
});

it('prefers a matching vendor delivery zone', function (): void {
    $origin = new GeoLocation(6.4550, 3.3841);
    $vendor = vendorAt($origin);
    $vendor->setDeliveryZones([new DeliveryZone('Island', new Money(30000, 'NGN'), 15.0)]);

    $quote = calc()->quote($vendor, new GeoLocation(6.50, 3.38), new Money(300000, 'NGN'));
    expect($quote->fee->minorUnits)->toBe(30000)->and($quote->zoneName)->toBe('Island');
});
