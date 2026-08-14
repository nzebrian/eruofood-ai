<?php

declare(strict_types=1);

use EruoFood\Geo\Interface\Http\Controller\AddressController;
use EruoFood\Geo\Interface\Http\Controller\DeliveryZoneController;
use EruoFood\Geo\Interface\Http\Controller\GeoAdminController;
use EruoFood\Geo\Interface\Http\Controller\MerchantLocationController;
use EruoFood\Geo\Interface\Http\Controller\RiderLocationController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Maps & Geolocation routes (mounted under /api/v1 by the module provider).
|
| Throttles here are cost controls first and abuse controls second. Every
| geocode, route and autocomplete call bills per request, so a client stuck in a
| loop is a financial incident that produces no error log — the bill simply
| arrives at the end of the month. The limits are set against what a human using
| the app can plausibly do:
|
|   autocomplete  60/min  a fast typist, debounced by the client
|   addresses     30/min  saving an address is a deliberate act
|   rider report  30/min  a position every two seconds is already generous
|   zone check    60/min  cheap, no provider call, but still a database read
|
| Reads of a merchant's public location are unauthenticated because a
| restaurant's neighbourhood is already public; what the presenter withholds is
| the precision, not the existence.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/geo')->group(function (): void {
    // ---- Public: where a merchant is, coarsely ----
    Route::get('merchants/{ownerType}/{ownerId}/location', [MerchantLocationController::class, 'publicShow'])
        ->middleware('throttle:120,1')
        ->whereIn('ownerType', ['vendor', 'store']);

    Route::middleware('auth.jwt')->group(function (): void {
        /*
        | ---- The customer's address book ----
        |
        | Nothing here takes a user id. The owner comes from the token and
        | nowhere else, so there is no parameter an attacker could change to
        | reach somebody else's addresses.
        */
        Route::prefix('addresses')->group(function (): void {
            Route::get('/', [AddressController::class, 'index']);
            Route::post('/', [AddressController::class, 'store'])->middleware('throttle:30,1');
            Route::get('{id}', [AddressController::class, 'show']);
            Route::patch('{id}', [AddressController::class, 'update']);
            Route::post('{id}/relocate', [AddressController::class, 'relocate'])->middleware('throttle:20,1');
            Route::post('{id}/default', [AddressController::class, 'makeDefault']);
            Route::delete('{id}', [AddressController::class, 'destroy']);
        });

        // The most expensive endpoint per useful outcome on the platform: a
        // keystroke-per-request client makes twenty billable calls to save one
        // address.
        Route::get('autocomplete', [AddressController::class, 'autocomplete'])
            ->middleware('throttle:60,1');

        /*
        | ---- Merchant trading locations ----
        |
        | Ownership is re-checked against the merchant record inside the
        | controller; the id in the URL is never taken as a claim to it.
        */
        Route::prefix('merchants/{ownerType}/{ownerId}')->whereIn('ownerType', ['vendor', 'store'])->group(function (): void {
            Route::get('location/manage', [MerchantLocationController::class, 'show']);
            Route::post('location', [MerchantLocationController::class, 'store'])->middleware('throttle:20,1');
            Route::post('location/confirm', [MerchantLocationController::class, 'confirm']);

            // ---- Delivery zones ----
            Route::get('zones', [DeliveryZoneController::class, 'index']);
            Route::post('zones', [DeliveryZoneController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('zones/{zoneId}', [DeliveryZoneController::class, 'update']);
            Route::post('zones/check', [DeliveryZoneController::class, 'check'])->middleware('throttle:60,1');
        });

        /*
        | ---- Rider positions ----
        |
        | A rider writes only their own, checked against the rider record. There
        | is deliberately no route that lists every rider's position: that is
        | dispatch and live tracking, and it belongs to a later milestone.
        */
        Route::prefix('riders/{riderId}')->group(function (): void {
            Route::post('location', [RiderLocationController::class, 'report'])->middleware('throttle:30,1');
            Route::get('location', [RiderLocationController::class, 'own']);
            Route::delete('location', [RiderLocationController::class, 'goOffline']);
        });
    });

    /*
    | ---- Back office / Global Command Centre ----
    |
    | Permissions are applied per route rather than to the group, because
    | reading provider health and correcting somebody's address are different
    | powers. Health and coverage need only `geo.read`; changing a location's
    | verification status needs `geo.manage`.
    */
    Route::middleware('auth.jwt')->prefix('admin')->group(function (): void {
        Route::middleware('permission:geo.read')->group(function (): void {
            Route::get('provider-health', [GeoAdminController::class, 'providerHealth']);
            Route::get('pricing-mode', [GeoAdminController::class, 'pricingMode']);
            Route::get('coverage', [GeoAdminController::class, 'coverage']);
            Route::get('locations/{id}', [GeoAdminController::class, 'showLocation']);
            Route::post('measure', [GeoAdminController::class, 'measure'])->middleware('throttle:30,1');
        });

        Route::middleware('permission:geo.manage')->group(function (): void {
            Route::post('locations/{id}/confirm', [GeoAdminController::class, 'confirmLocation']);
            Route::post('locations/{id}/dispute', [GeoAdminController::class, 'disputeLocation']);
        });
    });
});
