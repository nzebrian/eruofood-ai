<?php

declare(strict_types=1);

use EruoFood\Marketplace\Interface\Http\Controller\Admin\VendorAdminController;
use EruoFood\Marketplace\Interface\Http\Controller\CartController;
use EruoFood\Marketplace\Interface\Http\Controller\CheckoutController;
use EruoFood\Marketplace\Interface\Http\Controller\DeliveryController;
use EruoFood\Marketplace\Interface\Http\Controller\MenuController;
use EruoFood\Marketplace\Interface\Http\Controller\MenuManagementController;
use EruoFood\Marketplace\Interface\Http\Controller\OrderController;
use EruoFood\Marketplace\Interface\Http\Controller\RiderController;
use EruoFood\Marketplace\Interface\Http\Controller\SearchController;
use EruoFood\Marketplace\Interface\Http\Controller\VendorController;
use EruoFood\Marketplace\Interface\Http\Controller\VendorManagementController;
use EruoFood\Marketplace\Interface\Http\Controller\VendorReviewController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Marketplace routes (mounted under /api/v1 by the module provider)
|------------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // ---- Public discovery ----
    Route::get('vendors', [VendorController::class, 'index']);
    Route::get('vendors/{slug}', [VendorController::class, 'show']);
    Route::get('vendors/{id}/reviews', [VendorController::class, 'reviews']);
    Route::get('vendors/{vendorId}/menu', [MenuController::class, 'items']);
    Route::get('vendors/{vendorId}/menu/categories', [MenuController::class, 'categories']);
    Route::get('search/vendors', [SearchController::class, 'vendors']);
    Route::get('search/menu-items', [SearchController::class, 'items']);

    // ---- Authenticated ----
    Route::middleware('auth.jwt')->group(function (): void {
        // The caller's own vendors (distinct path so it never shadows /vendors/{slug}).
        Route::get('me/vendors', [VendorManagementController::class, 'mine']);

        // Vendor onboarding & storefront management (owner)
        Route::prefix('vendors')->group(function (): void {
            Route::post('/', [VendorManagementController::class, 'store']);
            Route::put('{id}', [VendorManagementController::class, 'update']);
            Route::put('{id}/hours', [VendorManagementController::class, 'setHours']);
            Route::put('{id}/delivery-zones', [VendorManagementController::class, 'setDeliveryZones']);
            Route::put('{id}/branches', [VendorManagementController::class, 'setBranches']);
            Route::put('{id}/images', [VendorManagementController::class, 'setImages']);
            Route::get('{id}/dashboard', [VendorManagementController::class, 'dashboard']);
            Route::get('{vendorId}/orders', [OrderController::class, 'vendorIndex']);

            // Menu management (owner)
            Route::post('{vendorId}/menu', [MenuManagementController::class, 'storeItem']);
            Route::post('{vendorId}/menu/categories', [MenuManagementController::class, 'storeCategory']);

            // Reviews
            Route::post('{id}/reviews', [VendorReviewController::class, 'store'])->middleware('throttle:20,1');
        });

        Route::put('menu-items/{itemId}', [MenuManagementController::class, 'updateItem']);
        Route::delete('menu-items/{itemId}', [MenuManagementController::class, 'deleteItem']);
        Route::patch('menu-items/{itemId}/availability', [MenuManagementController::class, 'setAvailability']);
        Route::patch('menu-items/{itemId}/featured', [MenuManagementController::class, 'setFeatured']);
        Route::put('menu-items/{itemId}/promotion', [MenuManagementController::class, 'setPromotion']);
        Route::patch('menu-items/{itemId}/stock', [MenuManagementController::class, 'restock']);
        Route::post('menu-items/{itemId}/describe', [MenuManagementController::class, 'describe'])->middleware('throttle:20,1');
        Route::delete('menu-categories/{categoryId}', [MenuManagementController::class, 'deleteCategory']);

        // Cart
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'add']);
        Route::put('cart/items', [CartController::class, 'updateItem']);
        Route::delete('cart/items', [CartController::class, 'remove']);
        Route::delete('cart', [CartController::class, 'clear']);

        // Checkout & orders
        Route::post('checkout', [CheckoutController::class, 'store']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{id}/status', [OrderController::class, 'advance']);
        Route::get('orders/{orderId}/delivery', [DeliveryController::class, 'forOrder']);

        // Delivery (vendor assigns; rider progresses)
        Route::post('deliveries/{id}/assign', [DeliveryController::class, 'assign']);
        Route::post('deliveries/{id}/status', [DeliveryController::class, 'advance']);
        Route::post('deliveries/{id}/track', [DeliveryController::class, 'track']);

        // Rider self-service
        Route::post('riders', [RiderController::class, 'store']);
        Route::get('riders/me', [RiderController::class, 'me']);
        Route::patch('riders/me/status', [RiderController::class, 'setStatus']);
        Route::post('riders/me/location', [RiderController::class, 'updateLocation']);
    });

    // ---- Admin (RBAC) ----
    Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin/marketplace')->group(function (): void {
        Route::post('vendors/{id}/verify', [VendorAdminController::class, 'verify']);
        Route::post('vendors/{id}/reject', [VendorAdminController::class, 'reject']);
        Route::post('vendors/{id}/suspend', [VendorAdminController::class, 'suspend']);
        Route::post('vendors/{id}/feature', [VendorAdminController::class, 'feature']);
    });
});
