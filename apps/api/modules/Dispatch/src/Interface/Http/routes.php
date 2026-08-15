<?php

declare(strict_types=1);

use EruoFood\Dispatch\Interface\Http\Controller\DispatchAdminController;
use EruoFood\Dispatch\Interface\Http\Controller\RiderOfferController;
use EruoFood\Dispatch\Interface\Http\Controller\RiderVehicleController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Dispatch, Vehicles & Rider Assignment routes (mounted under /api by the module
| provider).
|
| Two audiences, two prefixes, two completely different threat models.
|
| ## /v1/dispatch — riders
|
| Nothing here takes a rider id. Every endpoint resolves the rider from the
| authenticated account, which is what makes rider self-assignment structurally
| impossible rather than merely forbidden: an endpoint that cannot express
| "assign me to that delivery" cannot be tricked into doing it.
|
| Nothing here takes coordinates either. A rider's position reaches dispatch
| through M25's own endpoint, under M25's authorisation. A rider who could post
| a position to a dispatch endpoint could put themselves outside every
| restaurant in Lagos at once.
|
| Throttles are set against what a person using the app can plausibly do:
|
|   offer read      120/min  the app polls while an offer is live
|   accept/decline   30/min  answering is a deliberate act; 30 covers retries
|   state advance    60/min  six journey steps, plus retries on a bad connection
|   vehicle writes   20/min  registering a vehicle is rare and deliberate
|
| ## /v1/admin/dispatch — the Control Centre backend
|
| Split by permission, not by prefix. `dispatch.read` sees; `dispatch.manage`
| changes who earns and who eats. A single permission would have handed the
| second to everyone who needed the first.
|
| M26 builds no Control Centre UI. These are the backend endpoints it will use.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/dispatch')->middleware('auth.jwt')->group(function (): void {
    /*
    | The offer a rider is looking at, and their answer to it.
    |
    | `current` is polled by the app while an offer is live, which is why its
    | throttle is the loosest here — and why the response carries both the
    | expiry instant and the countdown, so a slow network cannot make the app
    | show more time than the rider actually has.
    */
    Route::get('offers/current', [RiderOfferController::class, 'current'])
        ->middleware('throttle:120,1');

    Route::post('offers/{offerId}/accept', [RiderOfferController::class, 'accept'])
        ->middleware('throttle:30,1')
        ->whereUuid('offerId');

    Route::post('offers/{offerId}/decline', [RiderOfferController::class, 'decline'])
        ->middleware('throttle:30,1')
        ->whereUuid('offerId');

    // What the rider is carrying, and moving it along. The state list is
    // validated in the controller against the rider-drivable half of the
    // machine — `cancelled` and `reassignment_required` are not a rider's to
    // declare.
    Route::get('assignments/current', [RiderOfferController::class, 'currentAssignment'])
        ->middleware('throttle:120,1');

    Route::post('assignments/{assignmentId}/state', [RiderOfferController::class, 'advance'])
        ->middleware('throttle:60,1')
        ->whereUuid('assignmentId');

    /*
    | A rider's own vehicles.
    |
    | Every write here leaves the vehicle unusable or pushes it back to pending.
    | There is deliberately no approve endpoint on this prefix — approval needs
    | an operator, and it lives under /admin. Without that split, vehicle
    | verification would be a form riders fill in about themselves.
    */
    Route::prefix('vehicles')->group(function (): void {
        Route::get('/', [RiderVehicleController::class, 'index'])->middleware('throttle:60,1');
        Route::post('/', [RiderVehicleController::class, 'store'])->middleware('throttle:20,1');

        Route::get('{vehicleId}', [RiderVehicleController::class, 'show'])
            ->middleware('throttle:60,1')->whereUuid('vehicleId');

        Route::put('{vehicleId}/documents', [RiderVehicleController::class, 'updateDocuments'])
            ->middleware('throttle:20,1')->whereUuid('vehicleId');

        Route::post('{vehicleId}/submit', [RiderVehicleController::class, 'submit'])
            ->middleware('throttle:20,1')->whereUuid('vehicleId');

        Route::post('{vehicleId}/primary', [RiderVehicleController::class, 'makePrimary'])
            ->middleware('throttle:20,1')->whereUuid('vehicleId');

        Route::delete('{vehicleId}', [RiderVehicleController::class, 'retire'])
            ->middleware('throttle:20,1')->whereUuid('vehicleId');
    });
});

Route::prefix('v1/admin/dispatch')->middleware('auth.jwt')->group(function (): void {
    /*
    | Reading. Support answering "where is my order?" needs all of this and
    | should not need more.
    */
    Route::middleware('permission:dispatch.read')->group(function (): void {
        Route::get('queue', [DispatchAdminController::class, 'queue']);
        Route::get('active', [DispatchAdminController::class, 'active']);
        Route::get('failures', [DispatchAdminController::class, 'failures']);
        Route::get('availability', [DispatchAdminController::class, 'availability']);
        Route::get('health', [DispatchAdminController::class, 'health']);
        Route::get('vehicles/queue', [DispatchAdminController::class, 'vehicleQueue']);

        Route::get('requests/{requestId}/history', [DispatchAdminController::class, 'history'])
            ->whereUuid('requestId');
    });

    /*
    | Changing. Every one of these is audited before the response is returned:
    | manual assignment takes a delivery off one rider and gives it to another,
    | forced reassignment interrupts somebody mid-job, cancellation ends a
    | customer's order. Each requires a stated reason for the same reason.
    */
    Route::middleware('permission:dispatch.manage')->group(function (): void {
        Route::post('requests/{requestId}/assign', [DispatchAdminController::class, 'assign'])
            ->whereUuid('requestId');

        Route::post('requests/{requestId}/cancel', [DispatchAdminController::class, 'cancel'])
            ->whereUuid('requestId');

        Route::post('assignments/{assignmentId}/reassign', [DispatchAdminController::class, 'reassign'])
            ->whereUuid('assignmentId');

        Route::post('vehicles/{vehicleId}/approve', [DispatchAdminController::class, 'approveVehicle'])
            ->whereUuid('vehicleId');

        Route::post('vehicles/{vehicleId}/reject', [DispatchAdminController::class, 'rejectVehicle'])
            ->whereUuid('vehicleId');

        Route::post('vehicles/{vehicleId}/suspend', [DispatchAdminController::class, 'suspendVehicle'])
            ->whereUuid('vehicleId');
    });
});
