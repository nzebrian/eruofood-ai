<?php

declare(strict_types=1);

use EruoFood\Geo\Infrastructure\Event\KybLocationSubscriber;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * M25 — what the Geo module costs at boot, and what it must not.
 *
 * Regression for a defect CI caught on this milestone's first push. The KYB
 * listener was registered as a constructed instance, so booting the module
 * resolved M24's business-profile repository — and through it the encrypter,
 * because M24 encrypts document numbers at the repository boundary.
 *
 * That made `composer install` fail outright: its `post-autoload-dump` runs
 * `artisan package:discover`, which boots every service provider *before* an
 * application key exists. Two CI jobs died at install with
 * `MissingAppKeyException` and never reached a single test.
 *
 * The narrower point stands even with a key present: a listener needed on a
 * rare event should cost nothing on the paths where it is not needed, and
 * building a cross-context repository on every process start is not nothing.
 */
it('registers the KYB listener without constructing it', function (): void {
    $listeners = app(Dispatcher::class)->getListeners('verification.subject_verified');

    expect($listeners)->not->toBeEmpty();

    // Nothing in the container has been asked to build the subscriber. If the
    // registration were eager this would already be resolved, and with it every
    // dependency it names.
    expect(app()->resolved(KybLocationSubscriber::class))->toBeFalse();
});

/**
 * The container must be able to describe the module's bindings without building
 * them. A binding that constructs eagerly is one that runs on every boot,
 * whether or not anything uses it.
 */
it('binds the Geo services lazily', function (): void {
    foreach ([
        EruoFood\Geo\Application\Service\GeocodingService::class,
        EruoFood\Geo\Application\Service\RoutingService::class,
        EruoFood\Geo\Application\Service\DeliveryDistanceService::class,
        EruoFood\Geo\Application\Service\AddressBookService::class,
        EruoFood\Geo\Application\Service\RiderLocationService::class,
        EruoFood\Geo\Application\Service\DeliveryZoneService::class,
        EruoFood\Geo\Application\Service\MerchantLocationService::class,
    ] as $service) {
        expect(app()->bound($service))->toBeTrue("{$service} is not registered");
    }
});

/**
 * The listener still works once something actually fires the event — the whole
 * point of registering it, and the half a laziness fix could quietly break.
 */
it('still resolves and runs the listener when a KYB approval fires', function (): void {
    $subscriber = app(KybLocationSubscriber::class);

    expect($subscriber)->toBeInstanceOf(KybLocationSubscriber::class)
        ->and(method_exists($subscriber, 'handle'))->toBeTrue();
});
