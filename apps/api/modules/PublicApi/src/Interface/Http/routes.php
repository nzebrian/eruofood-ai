<?php

declare(strict_types=1);

use EruoFood\PublicApi\Interface\Http\Controller\Developer\ApiKeyController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\ApplicationController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\DeveloperController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\UsageController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\WebhookController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\FoodsController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\MetaController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\RecipesController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Public API & Developer Platform routes
|------------------------------------------------------------------------------
| Two distinct surfaces:
|
|  1. The PUBLIC API at /api/public/v1 — the controlled external surface. Every
|     request passes the gateway stack: request-context → API-key auth → rate
|     limit → quota, and each data route additionally requires a granted scope.
|     No internal endpoint is exposed; controllers return transformed resources.
|
|  2. The DEVELOPER PORTAL at /api/v1/developer — internal, JWT-authenticated
|     management of developer accounts, applications, keys, webhooks and usage.
|     API keys are never accepted here; the portal is for humans, not clients.
|
| Adding /api/public/v2 later means adding a sibling group — v1 is untouched.
*/

// ---- Public API (v1) ----
Route::prefix('public/v1')
    ->middleware(['publicapi.context'])
    ->group(function (): void {
        // Unauthenticated meta.
        Route::get('status', [MetaController::class, 'status'])->name('public.status');
        Route::get('scopes', [MetaController::class, 'scopes'])->name('public.scopes');

        // Authenticated + rate-limited + quota-governed data surface.
        Route::middleware(['publicapi.auth', 'publicapi.ratelimit', 'publicapi.quota'])->group(function (): void {
            Route::middleware('publicapi.scope:foods:read')->group(function (): void {
                Route::get('foods', [FoodsController::class, 'index'])->name('public.foods.index');
                Route::get('foods/{slug}', [FoodsController::class, 'show'])->name('public.foods.show');
            });
            Route::middleware('publicapi.scope:recipes:read')->group(function (): void {
                Route::get('recipes', [RecipesController::class, 'index'])->name('public.recipes.index');
                Route::get('recipes/{slug}', [RecipesController::class, 'show'])->name('public.recipes.show');
            });
        });
    });

// ---- Developer Portal (internal, JWT) ----
Route::prefix('v1/developer')->middleware('auth.jwt')->group(function (): void {
    Route::post('register', [DeveloperController::class, 'register']);
    Route::get('me', [DeveloperController::class, 'me']);

    Route::get('applications', [ApplicationController::class, 'index']);
    Route::post('applications', [ApplicationController::class, 'store']);
    Route::get('applications/{id}', [ApplicationController::class, 'show']);
    Route::put('applications/{id}/scopes', [ApplicationController::class, 'updateScopes']);
    Route::post('applications/{id}/suspend', [ApplicationController::class, 'suspend']);

    Route::get('applications/{applicationId}/keys', [ApiKeyController::class, 'index']);
    Route::post('applications/{applicationId}/keys', [ApiKeyController::class, 'store']);
    Route::post('keys/{keyId}/rotate', [ApiKeyController::class, 'rotate']);
    Route::delete('keys/{keyId}', [ApiKeyController::class, 'destroy']);

    Route::get('applications/{applicationId}/webhooks', [WebhookController::class, 'index']);
    Route::post('applications/{applicationId}/webhooks', [WebhookController::class, 'store']);
    Route::put('applications/{applicationId}/webhooks/{id}', [WebhookController::class, 'update']);
    Route::post('applications/{applicationId}/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret']);
    Route::delete('applications/{applicationId}/webhooks/{id}', [WebhookController::class, 'destroy']);
    Route::get('applications/{applicationId}/webhooks/{id}/deliveries', [WebhookController::class, 'deliveries']);

    Route::get('applications/{applicationId}/usage', [UsageController::class, 'show']);
});
