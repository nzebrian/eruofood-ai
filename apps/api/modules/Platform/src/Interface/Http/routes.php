<?php

declare(strict_types=1);

use EruoFood\Platform\Interface\Http\Controller\HealthController;
use EruoFood\Platform\Interface\Http\Controller\ReadinessController;
use EruoFood\Platform\Interface\Http\Controller\ReconciliationController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Platform module routes
|------------------------------------------------------------------------------
| Foundation/operational endpoints. Loaded by PlatformServiceProvider under the
| /api/v1 prefix. No business routes here.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('platform.health');
    Route::get('/ready', ReadinessController::class)->name('platform.ready');

    /*
    | Client recovery. A returning app asks what happened to the requests it
    | sent before it lost the connection, rather than guessing from local state.
    |
    | Authenticated because an idempotency key is a client-chosen string:
    | answering on the key alone would let anyone enumerate keys and read other
    | people's payment outcomes. Throttled because a client that has just
    | reconnected should reconcile a batch once, not poll.
    */
    Route::middleware(['auth.jwt', 'throttle:30,1'])
        ->post('/reconcile', ReconciliationController::class)
        ->name('platform.reconcile');
});
