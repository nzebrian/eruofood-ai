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
                'INVALID_CREDENTIALS', 'INVALID_TWO_FACTOR_CODE', 'PUBLICAPI_UNAUTHENTICATED',
                // An unsigned, forged or replayed provider callback. The
                // webhook controller normally answers this itself; the
                // mapping is here so an escape cannot downgrade it to 400.
                'VERIFICATION_WEBHOOK_REJECTED' => 401,
                'ACCOUNT_SUSPENDED', 'NOT_AUTHORIZED' => 403,
                'USER_NOT_FOUND', 'CATALOG_RESOURCE_NOT_FOUND',
                'AI_PROMPT_NOT_FOUND', 'AI_CONVERSATION_NOT_FOUND',
                'NUTRITION_RESOURCE_NOT_FOUND', 'MARKETPLACE_RESOURCE_NOT_FOUND',
                'COMMERCE_RESOURCE_NOT_FOUND', 'PAYMENTS_RESOURCE_NOT_FOUND',
                'NOTIFICATIONS_RESOURCE_NOT_FOUND', 'VERIFICATION_RESOURCE_NOT_FOUND',
                'ANALYTICS_RESOURCE_NOT_FOUND', 'ADMIN_RESOURCE_NOT_FOUND',
                'SEARCH_RESOURCE_NOT_FOUND', 'SUPPORT_RESOURCE_NOT_FOUND',
                // A geocode that found nothing is the caller's address to
                // correct; an unreachable provider is ours to absorb, and maps
                // to 503 below. Answering both the same way would tell somebody
                // their real address is wrong during an outage.
                'GEO_RESOURCE_NOT_FOUND', 'GEO_ADDRESS_NOT_FOUND',
                'REVIEWS_RESOURCE_NOT_FOUND', 'LOYALTY_RESOURCE_NOT_FOUND',
                'PUBLICAPI_RESOURCE_NOT_FOUND' => 404,
                // A concurrent writer won, or a duplicate request is still in
                // flight. Nothing was changed either way, so the client may
                // safely retry — 409 says exactly that.
                'CONCURRENCY_CONFLICT', 'IDEMPOTENCY_IN_FLIGHT' => 409,
                'EMAIL_ALREADY_REGISTERED', 'DUPLICATE_SLUG', 'ALREADY_REVIEWED',
                'MARKETPLACE_CONFLICT', 'COMMERCE_CONFLICT', 'PAYMENTS_CONFLICT',
                'NOTIFICATIONS_CONFLICT', 'ADMIN_CONFLICT', 'SEARCH_CONFLICT',
                'SUPPORT_CONFLICT', 'REVIEWS_CONFLICT', 'LOYALTY_CONFLICT', 'VERIFICATION_CONFLICT',
                'PUBLICAPI_CONFLICT' => 409,
                // The key was already spent on a different payload. Retrying will
                // not help — the client must use a fresh key.
                'IDEMPOTENCY_KEY_REUSED',
                'INVALID_ARGUMENT', 'NUTRITION_PROFILE_INCOMPLETE' => 422,
                'MARKETPLACE_NOT_AUTHORIZED', 'COMMERCE_NOT_AUTHORIZED',
                'PAYMENTS_NOT_AUTHORIZED', 'NOTIFICATIONS_NOT_AUTHORIZED',
                'ANALYTICS_NOT_AUTHORIZED', 'ADMIN_NOT_AUTHORIZED',
                'SEARCH_NOT_AUTHORIZED', 'SUPPORT_NOT_AUTHORIZED',
                'REVIEWS_NOT_AUTHORIZED', 'LOYALTY_NOT_AUTHORIZED',
                'VERIFICATION_NOT_AUTHORIZED', 'GEO_NOT_AUTHORIZED',
                // Step-up is a 403 with a machine-readable next step: the
                // caller is not forbidden, they are not yet verified enough,
                // and the error body names the level to obtain.
                'VERIFICATION_STEP_UP_REQUIRED',
                'PUBLICAPI_FORBIDDEN' => 403,
                'MARKETPLACE_INVALID_STATE', 'COMMERCE_INVALID_STATE',
                'PAYMENTS_INVALID_STATE', 'NOTIFICATIONS_INVALID_STATE',
                'ANALYTICS_INVALID_STATE', 'ADMIN_INVALID_STATE',
                'SEARCH_INVALID_QUERY', 'SUPPORT_INVALID_STATE',
                'REVIEWS_INVALID_STATE', 'LOYALTY_INVALID_STATE',
                'VERIFICATION_INVALID_STATE', 'VERIFICATION_INVALID_TRANSITION',
                'GEO_INVALID_STATE', 'GEO_INVALID_COORDINATES',
                'PUBLICAPI_INVALID_STATE' => 422,
                'AI_RATE_LIMIT_EXCEEDED', 'PUBLICAPI_RATE_LIMITED', 'PUBLICAPI_QUOTA_EXCEEDED',
                // Mapping APIs bill per request, so a spent budget is a
                // throttle rather than a fault: the caller should back off and
                // retry, not conclude the address is bad.
                'GEO_QUOTA_EXCEEDED' => 429,
                'AI_GENERATION_FAILED', 'PAYMENTS_PROVIDER_ERROR' => 502,
                'AI_PROVIDER_UNAVAILABLE', 'VERIFICATION_PROVIDER_UNAVAILABLE',
                // `GEO_ROUTING_UNAVAILABLE` is the honest refusal at the end of
                // the delivery-fee fallback chain. It is a 503 and not a 200
                // with a guessed price, which is the whole point of it existing.
                'GEO_PROVIDER_UNAVAILABLE', 'GEO_ROUTING_UNAVAILABLE' => 503,
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
