<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Provider;

use EruoFood\Commerce\Application\Port\CommerceAdvisor;
use EruoFood\Commerce\Application\Port\DiscountEngine;
use EruoFood\Commerce\Application\Port\InvoiceGenerator;
use EruoFood\Commerce\Application\Port\PricingStrategy;
use EruoFood\Commerce\Application\Port\ShippingCalculator;
use EruoFood\Commerce\Application\Port\TaxCalculator;
use EruoFood\Commerce\Application\Service\AdminDashboardService;
use EruoFood\Commerce\Application\Service\CartService;
use EruoFood\Commerce\Application\Service\CheckoutService;
use EruoFood\Commerce\Application\Service\InventoryService;
use EruoFood\Commerce\Application\Service\ProductService;
use EruoFood\Commerce\Application\Service\StoreService;
use EruoFood\Commerce\Domain\Cart\CartRepository;
use EruoFood\Commerce\Domain\Catalog\CategoryRepository;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Catalog\ProductReviewRepository;
use EruoFood\Commerce\Domain\Inventory\InventoryItemRepository;
use EruoFood\Commerce\Domain\Inventory\SupplierRepository;
use EruoFood\Commerce\Domain\Inventory\WarehouseRepository;
use EruoFood\Commerce\Domain\Order\OrderRepository;
use EruoFood\Commerce\Domain\Order\ReturnRequestRepository;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;
use EruoFood\Commerce\Domain\Promotion\PromotionRepository;
use EruoFood\Commerce\Domain\Shopping\ShoppingListRepository;
use EruoFood\Commerce\Domain\Shopping\WishlistRepository;
use EruoFood\Commerce\Domain\Store\StoreRepository;
use EruoFood\Commerce\Infrastructure\Ai\AiCommerceAdvisor;
use EruoFood\Commerce\Infrastructure\Invoice\OrderInvoiceGenerator;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentCartRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentCategoryRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentCouponRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentInventoryItemRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentOrderRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentProductRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentProductReviewRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentPromotionRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentReturnRequestRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentShoppingListRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentStoreRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentSupplierRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentWarehouseRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\EloquentWishlistRepository;
use EruoFood\Commerce\Infrastructure\Pricing\CataloguePricingStrategy;
use EruoFood\Commerce\Infrastructure\Pricing\CouponDiscountEngine;
use EruoFood\Commerce\Infrastructure\Pricing\FlatRateShippingCalculator;
use EruoFood\Commerce\Infrastructure\Pricing\VatTaxCalculator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Marketplace, Grocery & Commerce Platform.
 *
 * Binds every repository port to its Eloquent adapter; the tax, shipping,
 * pricing, discount and invoice ports to their default implementations; and the
 * AI shopping advisor to the adapter that bridges to the AI module's published
 * contract. Currency, the tax/shipping model and the verification policy come
 * from config and are injected as contextual primitives so services and
 * repositories stay constructor-clean.
 */
final class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];
        $currency = (string) $config->get('commerce.currency', 'NGN');
        $requireVerification = (bool) $config->get('commerce.require_verification', true);
        $lowStock = (int) $config->get('commerce.inventory.low_stock_threshold', 10);

        // Repositories → Eloquent adapters.
        $this->app->bind(StoreRepository::class, EloquentStoreRepository::class);
        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(ProductReviewRepository::class, EloquentProductReviewRepository::class);
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
        $this->app->bind(WishlistRepository::class, EloquentWishlistRepository::class);
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
        $this->app->bind(ReturnRequestRepository::class, EloquentReturnRequestRepository::class);
        $this->app->bind(PromotionRepository::class, EloquentPromotionRepository::class);
        $this->app->bind(CouponRepository::class, EloquentCouponRepository::class);
        $this->app->bind(InventoryItemRepository::class, EloquentInventoryItemRepository::class);
        $this->app->bind(WarehouseRepository::class, EloquentWarehouseRepository::class);
        $this->app->bind(SupplierRepository::class, EloquentSupplierRepository::class);
        $this->app->bind(ShoppingListRepository::class, EloquentShoppingListRepository::class);

        // Ports → adapters.
        $this->app->bind(CommerceAdvisor::class, AiCommerceAdvisor::class);
        $this->app->bind(PricingStrategy::class, CataloguePricingStrategy::class);
        $this->app->bind(DiscountEngine::class, CouponDiscountEngine::class);
        $this->app->bind(InvoiceGenerator::class, OrderInvoiceGenerator::class);

        $this->app->bind(TaxCalculator::class, function () use ($config): VatTaxCalculator {
            return new VatTaxCalculator(
                (int) $config->get('commerce.tax.rate_bps', 750),
                (bool) $config->get('commerce.tax.inclusive', false),
            );
        });
        $this->app->bind(ShippingCalculator::class, function () use ($config, $currency): FlatRateShippingCalculator {
            /** @var array{flat_fee: int, per_item_fee: int, free_over: int} $s */
            $s = $config->get('commerce.shipping');

            return new FlatRateShippingCalculator($s['flat_fee'], $s['per_item_fee'], $s['free_over'], $currency);
        });

        // Contextual primitives (currency).
        foreach ([
            EloquentProductRepository::class, EloquentCartRepository::class,
            EloquentOrderRepository::class, EloquentReturnRequestRepository::class,
            CartService::class, CheckoutService::class, AdminDashboardService::class,
            \EruoFood\Commerce\Interface\Http\Controller\ProductManagementController::class,
        ] as $needsCurrency) {
            $this->app->when($needsCurrency)->needs('$currency')->give($currency);
        }

        // Verification policy + inventory defaults.
        $this->app->when(StoreService::class)->needs('$requireVerification')->give($requireVerification);
        $this->app->when(ProductService::class)->needs('$requireVerification')->give($requireVerification);
        $this->app->when(InventoryService::class)->needs('$defaultLowStockThreshold')->give($lowStock);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
    }
}
