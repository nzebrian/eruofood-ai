<?php

declare(strict_types=1);

use EruoFood\Platform\Interface\Http\Controller\HealthController;
use EruoFood\Platform\Interface\Http\Controller\ReadinessController;
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
});
