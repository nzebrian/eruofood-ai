<?php

declare(strict_types=1);

use EruoFood\Verification\Interface\Http\Controller\BusinessVerificationController;
use EruoFood\Verification\Interface\Http\Controller\PhoneVerificationController;
use EruoFood\Verification\Interface\Http\Controller\ReviewController;
use EruoFood\Verification\Interface\Http\Controller\RiderVerificationController;
use EruoFood\Verification\Interface\Http\Controller\VerificationController;
use EruoFood\Verification\Interface\Http\Controller\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Verification routes — KYC / KYB (mounted under /api/v1 by the module provider).
|
| Three tiers, with deliberately different exposure:
|
|   webhooks/{provider}   public, provider-signed, tightly throttled
|   me, start, rider, business   authenticated subjects, scoped to themselves
|   admin/...             back office, gated per action on a specific permission
|
| Starting a verification is throttled harder than reading one: each start
| creates a provider session, which costs money and is the obvious lever for
| someone trying to run up a bill or brute-force a document.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/verification')->group(function (): void {
    // ---- Provider callbacks (public; the provider signs the payload) ----
    // Unauthenticated by necessity. The signature is the authentication, and a
    // failed one returns a bare 401 without explaining itself.
    Route::post('webhooks/{provider}', [WebhookController::class, 'handle'])
        ->middleware('throttle:120,1');

    // ---- The authenticated subject's own verification ----
    Route::middleware('auth.jwt')->group(function (): void {
        Route::get('me', [VerificationController::class, 'me']);
        Route::post('start', [VerificationController::class, 'start'])->middleware('throttle:10,60');
        Route::get('cases/{id}', [VerificationController::class, 'show']);

        // ---- Progressive verification: the cheap rung ----
        // Requesting is throttled hard because each request sends an SMS, which
        // costs money and lands on somebody's phone; confirming is throttled
        // separately because it is a guessing surface.
        Route::get('level', [PhoneVerificationController::class, 'level']);
        Route::post('phone/request', [PhoneVerificationController::class, 'request'])->middleware('throttle:5,60');
        Route::post('phone/confirm', [PhoneVerificationController::class, 'confirm'])->middleware('throttle:10,60');

        // ---- Rider KYC ----
        Route::post('rider/start', [RiderVerificationController::class, 'start'])->middleware('throttle:10,60');
        Route::get('rider/status', [RiderVerificationController::class, 'status']);

        // ---- Business KYB (restaurants and groceries alike) ----
        Route::prefix('business')->group(function (): void {
            Route::post('profiles', [BusinessVerificationController::class, 'submit'])->middleware('throttle:20,60');
            Route::post('profiles/{profileId}/representatives', [BusinessVerificationController::class, 'addRepresentative']);
            Route::post('profiles/{profileId}/verify-registration', [BusinessVerificationController::class, 'verifyRegistration'])
                ->middleware('throttle:10,60');
            Route::post('representatives/{representativeId}/verify', [BusinessVerificationController::class, 'verifyRepresentative'])
                ->middleware('throttle:10,60');
            Route::get('{kind}/{businessId}/status', [BusinessVerificationController::class, 'status']);
        });
    });

    /*
    | ---- Back office ----
    |
    | Permissions are applied per route rather than to the group, because seeing
    | the queue, deciding a case and opening someone's documents are three
    | different powers. A general administrator holds the first and not the
    | third: clearing a backlog never requires reading identity data.
    */
    Route::middleware('auth.jwt')->prefix('admin')->group(function (): void {
        Route::middleware('permission:verification.read')->group(function (): void {
            Route::get('queue', [ReviewController::class, 'queue']);
            Route::get('reason-codes', [ReviewController::class, 'reasonCodes']);
            Route::get('cases/{id}', [ReviewController::class, 'show']);
            Route::get('cases/{id}/history', [ReviewController::class, 'history']);
        });

        Route::middleware('permission:verification.review')->group(function (): void {
            Route::post('cases/{id}/approve', [ReviewController::class, 'approve']);
            Route::post('cases/{id}/reject', [ReviewController::class, 'reject']);
            Route::post('cases/{id}/require-reverification', [ReviewController::class, 'requireReverification']);
        });

        // The narrowest permission on the platform, and the only route that
        // returns regulated identity data. Every call — granted or denied —
        // writes an immutable audit event.
        Route::middleware('permission:verification.pii')->group(function (): void {
            Route::get('cases/{id}/documents', [ReviewController::class, 'documents']);
        });
    });
});
