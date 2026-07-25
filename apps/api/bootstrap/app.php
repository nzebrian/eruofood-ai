<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
|------------------------------------------------------------------------------
| EruoFood AI — Application bootstrap (Laravel 12 style).
|------------------------------------------------------------------------------
| Module route files are registered here via `then()`. Each bounded context
| owns its own routes file under modules/<Context>/src/Interface/Http/routes.php
| and is wired in by that module's service provider (see bootstrap/providers.php).
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API is stateless by default; per-route throttling is applied in modules.
        $middleware->api(prepend: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain exceptions are mapped to RFC 7807 problem responses in a
        // dedicated handler once modules are populated (Phase 3+).
    })
    ->create();
