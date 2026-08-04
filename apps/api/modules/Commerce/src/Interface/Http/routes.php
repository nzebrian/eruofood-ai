<?php

declare(strict_types=1);

use EruoFood\Commerce\Interface\Http\Controller\Admin\CategoryAdminController;
use EruoFood\Commerce\Interface\Http\Controller\Admin\CommerceAdminController;
use EruoFood\Commerce\Interface\Http\Controller\Admin\CouponAdminController;
use EruoFood\Commerce\Interface\Http\Controller\Admin\ProductAdminController;
use EruoFood\Commerce\Interface\Http\Controller\Admin\PromotionAdminController;
use EruoFood\Commerce\Interface\Http\Controller\Admin\ReturnAdminController;
use EruoFood\Commerce\Interface\Http\Controller\Admin\StoreAdminController;
use EruoFood\Commerce\Interface\Http\Controller\AssistantController;
use EruoFood\Commerce\Interface\Http\Controller\CartController;
use EruoFood\Commerce\Interface\Http\Controller\CategoryController;
use EruoFood\Commerce\Interface\Http\Controller\CheckoutController;
use EruoFood\Commerce\Interface\Http\Controller\InventoryController;
use EruoFood\Commerce\Interface\Http\Controller\OrderController;
use EruoFood\Commerce\Interface\Http\Controller\ProductController;
use EruoFood\Commerce\Interface\Http\Controller\ProductManagementController;
use EruoFood\Commerce\Interface\Http\Controller\ProductReviewController;
use EruoFood\Commerce\Interface\Http\Controller\PromotionController;
use EruoFood\Commerce\Interface\Http\Controller\ReturnController;
use EruoFood\Commerce\Interface\Http\Controller\ShoppingListController;
use EruoFood\Commerce\Interface\Http\Controller\StoreController;
use EruoFood\Commerce\Interface\Http\Controller\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Commerce routes — Marketplace, Grocery & Commerce Platform
| (mounted under /api/v1 by the module provider). All paths are prefixed with
| "commerce" so they never collide with the food-delivery Marketplace module.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/commerce')->group(function (): void {
    // ---- Public discovery ----
    Route::get('stores', [StoreController::class, 'index']);
    Route::get('stores/{slug}', [StoreController::class, 'show']);
    Route::get('stores/{storeId}/products', [StoreController::class, 'products']);

    Route::get('categories', [CategoryController::class, 'index']);

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/barcode/{barcode}', [ProductController::class, 'byBarcode']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('products/{id}/reviews', [ProductController::class, 'reviews']);

    Route::get('promotions', [PromotionController::class, 'index']);
    Route::get('promotions/flash-sales', [PromotionController::class, 'flashSales']);

    // AI recommendations / cross-sell / up-sell (guest-friendly)
    Route::get('recommendations', [AssistantController::class, 'recommendations']);
    Route::get('products/{productId}/cross-sell', [AssistantController::class, 'crossSell']);
    Route::get('products/{productId}/up-sell', [AssistantController::class, 'upSell']);

    // ---- Authenticated ----
    Route::middleware('auth.jwt')->group(function (): void {
        // Seller stores (distinct path so it never shadows /stores/{slug}).
        Route::get('me/stores', [StoreController::class, 'mine']);
        Route::post('stores', [StoreController::class, 'store']);
        Route::put('stores/{id}', [StoreController::class, 'update']);

        // Seller product management.
        Route::get('stores/{storeId}/manage/products', [ProductManagementController::class, 'storeProducts']);
        Route::post('stores/{storeId}/products', [ProductManagementController::class, 'store']);
        Route::get('stores/{storeId}/orders', [OrderController::class, 'storeOrders']);
        Route::put('products/{productId}', [ProductManagementController::class, 'update']);
        Route::delete('products/{productId}', [ProductManagementController::class, 'destroy']);
        Route::post('products/{productId}/submit', [ProductManagementController::class, 'submit']);
        Route::post('products/{productId}/describe', [ProductManagementController::class, 'describe'])->middleware('throttle:20,1');

        // Product reviews.
        Route::post('products/{productId}/reviews', [ProductReviewController::class, 'store'])->middleware('throttle:20,1');

        // Cart.
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'add']);
        Route::put('cart/items', [CartController::class, 'updateItem']);
        Route::delete('cart/items', [CartController::class, 'remove']);
        Route::post('cart/coupon', [CartController::class, 'applyCoupon']);
        Route::delete('cart', [CartController::class, 'clear']);

        // Wishlist.
        Route::get('wishlist', [WishlistController::class, 'show']);
        Route::post('wishlist', [WishlistController::class, 'add']);
        Route::delete('wishlist/{productId}', [WishlistController::class, 'remove']);

        // Checkout & orders.
        Route::get('checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('checkout', [CheckoutController::class, 'store']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::get('orders/{id}/invoice', [OrderController::class, 'invoice']);
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{id}/status', [OrderController::class, 'advance']);

        // Returns & refunds.
        Route::get('returns', [ReturnController::class, 'index']);
        Route::post('returns', [ReturnController::class, 'store']);

        // Smart shopping lists.
        Route::get('shopping-lists', [ShoppingListController::class, 'index']);
        Route::post('shopping-lists', [ShoppingListController::class, 'store']);
        Route::post('shopping-lists/build', [ShoppingListController::class, 'build'])->middleware('throttle:20,1');
        Route::post('shopping-lists/{id}/lines', [ShoppingListController::class, 'addLine']);
        Route::patch('shopping-lists/{id}/lines', [ShoppingListController::class, 'toggleLine']);
        Route::delete('shopping-lists/{id}/lines/{index}', [ShoppingListController::class, 'removeLine']);
        Route::delete('shopping-lists/{id}', [ShoppingListController::class, 'destroy']);

        // AI shopping assistant.
        Route::post('assistant/ask', [AssistantController::class, 'assist'])->middleware('throttle:20,1');
    });

    // ---- Admin (RBAC) ----
    Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin')->group(function (): void {
        Route::post('stores/{id}/verify', [StoreAdminController::class, 'verify']);
        Route::post('stores/{id}/suspend', [StoreAdminController::class, 'suspend']);

        Route::post('categories', [CategoryAdminController::class, 'store']);
        Route::delete('categories/{id}', [CategoryAdminController::class, 'destroy']);

        Route::get('products/queue', [ProductAdminController::class, 'queue']);
        Route::post('products/{id}/approve', [ProductAdminController::class, 'approve']);
        Route::post('products/{id}/reject', [ProductAdminController::class, 'reject']);
        Route::post('products/{id}/feature', [ProductAdminController::class, 'feature']);

        Route::post('promotions', [PromotionAdminController::class, 'store']);
        Route::delete('promotions/{id}', [PromotionAdminController::class, 'destroy']);

        Route::get('coupons', [CouponAdminController::class, 'index']);
        Route::post('coupons', [CouponAdminController::class, 'store']);
        Route::post('coupons/{id}/deactivate', [CouponAdminController::class, 'deactivate']);

        Route::get('returns', [ReturnAdminController::class, 'index']);
        Route::post('returns/{id}/resolve', [ReturnAdminController::class, 'resolve']);

        // Inventory & warehousing.
        Route::post('inventory/receive', [InventoryController::class, 'receive']);
        Route::post('inventory/{id}/adjust', [InventoryController::class, 'adjust']);
        Route::get('inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('inventory/products/{productId}', [InventoryController::class, 'forProduct']);
        Route::get('warehouses', [InventoryController::class, 'warehouses']);
        Route::post('warehouses', [InventoryController::class, 'createWarehouse']);
        Route::get('suppliers', [InventoryController::class, 'suppliers']);
        Route::post('suppliers', [InventoryController::class, 'createSupplier']);

        // Monitoring & reporting.
        Route::get('orders', [CommerceAdminController::class, 'orders']);
        Route::get('stores/{storeId}/sales', [CommerceAdminController::class, 'storeSales']);
    });
});
