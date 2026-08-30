<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\DataLifecycle\RetentionGate;
use EruoFood\Shared\Domain\Exception\DomainException;
use EruoFood\Shared\Domain\Schedule\Cadence;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use EruoFood\Shared\Infrastructure\Http\Middleware\AssignsCorrelationId;
use Illuminate\Console\Scheduling\Schedule;
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
        //
        // The correlation middleware is prepended so every later middleware,
        // controller, job and log line in the request shares one id — including
        // anything that fails before reaching a route.
        $middleware->api(prepend: [AssignsCorrelationId::class]);
    })
    /*
    | Recurring work, drained from the registry rather than listed here.
    |
    | Modules describe their own scheduled tasks (see ScheduleRegistry); this
    | bootstrap stays ignorant of what they are, which is what keeps a new
    | sweep a one-module change. Tasks registered as disabled are skipped.
    |
    | Note for anyone adding to this: there are deliberately **no dispatch
    | entries**. M26 ships with automatic dispatch switched off, and wiring
    | DispatchEngine into a scheduler is what "switching it on" means.
    */
    ->withSchedule(function (Schedule $schedule): void {
        $registry = app(ScheduleRegistry::class);

        // M42. A task that deletes or anonymises data past its retention window
        // needs a second, independent lock. `enabled` lives in the module that
        // registered the task; this flag lives with the operator who owns the
        // database. Both are off, and either one alone stops an unattended run —
        // which matters because `DeletionMode::isReversible()` is true for
        // exactly one mode, and it is not the one these tasks use.
        $retention = app(RetentionGate::class);

        foreach ($registry->enabled() as $task) {
            if ($task->destructiveRetention && ! $retention->allowsScheduledPurge()) {
                continue;
            }

            $event = match ($task->cadence) {
                Cadence::EveryMinute => $schedule->command($task->command)->everyMinute(),
                Cadence::EveryFiveMinutes => $schedule->command($task->command)->everyFiveMinutes(),
                Cadence::EveryFifteenMinutes => $schedule->command($task->command)->everyFifteenMinutes(),
                Cadence::Hourly => $schedule->command($task->command)->hourly(),
                Cadence::Daily => $schedule->command($task->command)->daily(),
            };

            if ($task->withoutOverlapping) {
                $event->withoutOverlapping($task->cadence->overlapGuardMinutes());
            }
        }
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
                'DISPATCH_RESOURCE_NOT_FOUND',
                'PUBLICAPI_RESOURCE_NOT_FOUND' => 404,
                // A concurrent writer won, or a duplicate request is still in
                // flight. Nothing was changed either way, so the client may
                // safely retry — 409 says exactly that.
                'CONCURRENCY_CONFLICT', 'IDEMPOTENCY_IN_FLIGHT',
                // Somebody else got there first, or the offer was answered
                // between the rider seeing it and tapping. Nothing changed, so
                // the client may safely move on to the next offer.
                'DISPATCH_ASSIGNMENT_CONFLICT', 'DISPATCH_OFFER_NOT_ANSWERABLE',
                // The rider was eligible when offered and is not now — their
                // insurance lapsed, or an operator suspended them. 409 because
                // nothing was changed and the reason is in the message.
                'DISPATCH_RIDER_NO_LONGER_ELIGIBLE' => 409,
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
                'DISPATCH_NOT_AUTHORIZED',
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
                // An illegal delivery transition, and a vehicle that cannot be
                // dispatched on. Both are the caller asking for something the
                // domain will not do, not a fault on our side.
                'DISPATCH_INVALID_STATE', 'DISPATCH_VEHICLE_NOT_DISPATCHABLE',
                'PUBLICAPI_INVALID_STATE' => 422,
                // Nobody could be found. A 503 rather than a 404: the delivery
                // exists and the platform simply has no rider for it right now,
                // which is an availability problem and not a missing resource.
                'DISPATCH_NO_ELIGIBLE_RIDERS' => 503,
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
