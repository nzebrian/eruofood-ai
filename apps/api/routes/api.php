<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API Routes (v1)
|------------------------------------------------------------------------------
| This file holds only cross-cutting/root routes. Every bounded context
| registers its own routes from within its module service provider, keeping
| the routing surface modular. No business endpoints exist in the foundation.
*/

Route::prefix('v1')->group(function (): void {
    // Foundation health/status routes are contributed by the Platform module.
    // Business routes are added by their respective modules in later phases.
});
