<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Exception\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        // Map domain exceptions to the standard API error envelope with a
        // sensible HTTP status. Keeps HTTP concerns out of the domain.
        $exceptions->render(function (DomainException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            // Map on the stable machine-readable code so the app shell stays
            // decoupled from any specific module's exception classes.
            $status = match ($e->errorCode()) {
                'INVALID_CREDENTIALS', 'INVALID_TWO_FACTOR_CODE' => 401,
                'ACCOUNT_SUSPENDED', 'NOT_AUTHORIZED' => 403,
                'USER_NOT_FOUND', 'CATALOG_RESOURCE_NOT_FOUND',
                'AI_PROMPT_NOT_FOUND', 'AI_CONVERSATION_NOT_FOUND',
                'NUTRITION_RESOURCE_NOT_FOUND', 'MARKETPLACE_RESOURCE_NOT_FOUND',
                'COMMERCE_RESOURCE_NOT_FOUND', 'PAYMENTS_RESOURCE_NOT_FOUND' => 404,
                'EMAIL_ALREADY_REGISTERED', 'DUPLICATE_SLUG', 'ALREADY_REVIEWED',
                'MARKETPLACE_CONFLICT', 'COMMERCE_CONFLICT', 'PAYMENTS_CONFLICT' => 409,
                'INVALID_ARGUMENT', 'NUTRITION_PROFILE_INCOMPLETE' => 422,
                'MARKETPLACE_NOT_AUTHORIZED', 'COMMERCE_NOT_AUTHORIZED',
                'PAYMENTS_NOT_AUTHORIZED' => 403,
                'MARKETPLACE_INVALID_STATE', 'COMMERCE_INVALID_STATE',
                'PAYMENTS_INVALID_STATE' => 422,
                'AI_RATE_LIMIT_EXCEEDED' => 429,
                'AI_GENERATION_FAILED', 'PAYMENTS_PROVIDER_ERROR' => 502,
                'AI_PROVIDER_UNAVAILABLE' => 503,
                default => 400,
            };

            return new JsonResponse([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                ],
            ], $status);
        });
    })
    ->create();
