<?php

declare(strict_types=1);

use EruoFood\PublicApi\Interface\Http\Controller\Developer\ApiKeyController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\ApplicationController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\DeveloperController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\UsageController;
use EruoFood\PublicApi\Interface\Http\Controller\Developer\WebhookController;
use EruoFood\PublicApi\Interface\Http\Controller\OAuth\AuthorizeController;
use EruoFood\PublicApi\Interface\Http\Controller\OAuth\TokenController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\FoodsController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\MetaController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\NutritionController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\OrdersController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\ProductsController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\RecipesController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\RestaurantsController;
use EruoFood\PublicApi\Interface\Http\Controller\Public\SearchController;
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

        // OAuth2 token endpoint. The client authenticates itself inside the
        // controller (HTTP Basic or body), so no gateway API-key auth here — but
        // it is still rate-limited to blunt credential-stuffing / brute force.
        Route::middleware('publicapi.ratelimit')
            ->post('oauth/token', [TokenController::class, 'issue'])
            ->name('public.oauth.token');

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

            // Restaurants + menus (Marketplace read façade).
            Route::middleware('publicapi.scope:restaurants:read')->group(function (): void {
                Route::get('restaurants', [RestaurantsController::class, 'index'])->name('public.restaurants.index');
                Route::get('restaurants/{slug}', [RestaurantsController::class, 'show'])->name('public.restaurants.show');
                Route::get('restaurants/{id}/menu', [RestaurantsController::class, 'menu'])->name('public.restaurants.menu');
            });

            // Products + categories (Commerce read façade).
            Route::middleware('publicapi.scope:products:read')->group(function (): void {
                Route::get('products', [ProductsController::class, 'index'])->name('public.products.index');
                Route::get('product-categories', [ProductsController::class, 'categories'])->name('public.products.categories');
                Route::get('products/{slug}', [ProductsController::class, 'show'])->name('public.products.show');
            });

            // Nutrition data (Nutrition read façade).
            Route::middleware('publicapi.scope:nutrition:read')->group(function (): void {
                Route::get('nutrition', [NutritionController::class, 'index'])->name('public.nutrition.index');
                Route::get('nutrition/{id}', [NutritionController::class, 'show'])->name('public.nutrition.show');
            });

            // Search (Search context pipeline via read port).
            Route::middleware('publicapi.scope:search:read')->group(function (): void {
                Route::get('search', [SearchController::class, 'query'])->name('public.search.query');
                Route::get('search/suggestions', [SearchController::class, 'suggestions'])->name('public.search.suggestions');
                Route::get('search/filters', [SearchController::class, 'filters'])->name('public.search.filters');
            });

            // Orders (customer-scoped; BOLA-enforced).
            Route::middleware('publicapi.scope:orders:read')->group(function (): void {
                Route::get('orders', [OrdersController::class, 'index'])->name('public.orders.index');
                Route::get('orders/{id}', [OrdersController::class, 'show'])->name('public.orders.show');
                Route::get('orders/{id}/status', [OrdersController::class, 'status'])->name('public.orders.status');
            });
            Route::middleware('publicapi.scope:orders:write')->group(function (): void {
                Route::post('orders', [OrdersController::class, 'store'])->name('public.orders.create');
                Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->name('public.orders.cancel');
            });
        });
    });

// ---- OAuth2 consent (internal JWT — the resource owner grants a code) ----
Route::prefix('v1/oauth')->middleware('auth.jwt')->group(function (): void {
    Route::post('authorize', [AuthorizeController::class, 'approve'])->name('oauth.authorize');
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
