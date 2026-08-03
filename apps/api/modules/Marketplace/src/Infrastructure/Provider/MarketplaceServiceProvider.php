<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Provider;

use EruoFood\Marketplace\Application\Port\DeliveryFeeCalculator;
use EruoFood\Marketplace\Application\Port\MenuDescriber;
use EruoFood\Marketplace\Application\Port\RouteOptimizer;
use EruoFood\Marketplace\Application\Service\CartService;
use EruoFood\Marketplace\Application\Service\CheckoutService;
use EruoFood\Marketplace\Application\Service\VendorDashboardService;
use EruoFood\Marketplace\Application\Service\VendorService;
use EruoFood\Marketplace\Domain\Cart\CartRepository;
use EruoFood\Marketplace\Domain\Delivery\DeliveryRepository;
use EruoFood\Marketplace\Domain\Menu\MenuCategoryRepository;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\Order\OrderRepository;
use EruoFood\Marketplace\Domain\Rider\RiderRepository;
use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Marketplace\Domain\Vendor\VendorReviewRepository;
use EruoFood\Marketplace\Infrastructure\Ai\AiMenuDescriber;
use EruoFood\Marketplace\Infrastructure\Delivery\NearestFirstRouteOptimizer;
use EruoFood\Marketplace\Infrastructure\Delivery\ZoneDeliveryFeeCalculator;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentCartRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentMenuCategoryRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentMenuItemRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentOrderRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentRiderRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentVendorRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentVendorReviewRepository;
use EruoFood\Marketplace\Interface\Http\Controller\MenuManagementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Restaurant, Vendor & Food Business Platform.
 *
 * Binds every repository port to its Eloquent adapter, the delivery-fee and
 * route-optimisation ports to their implementations, and the AI menu-describer
 * to the adapter that bridges to the AI module's published contract. Currency
 * and the verification policy come from config and are injected as contextual
 * primitives so services and repositories stay constructor-clean.
 */
final class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];
        $currency = (string) $config->get('marketplace.currency', 'NGN');
        $requireVerification = (bool) $config->get('marketplace.require_verification', true);

        // Repositories.
        $this->app->bind(VendorRepository::class, EloquentVendorRepository::class);
        $this->app->bind(VendorReviewRepository::class, EloquentVendorReviewRepository::class);
        $this->app->bind(MenuItemRepository::class, EloquentMenuItemRepository::class);
        $this->app->bind(MenuCategoryRepository::class, EloquentMenuCategoryRepository::class);
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(RiderRepository::class, EloquentRiderRepository::class);

        // Ports → adapters.
        $this->app->bind(MenuDescriber::class, AiMenuDescriber::class);
        $this->app->bind(RouteOptimizer::class, NearestFirstRouteOptimizer::class);
        $this->app->bind(DeliveryFeeCalculator::class, function () use ($config, $currency): ZoneDeliveryFeeCalculator {
            /** @var array{base_fee: int, per_km_fee: int, max_fee: int, free_over: int} $d */
            $d = $config->get('marketplace.delivery');

            return new ZoneDeliveryFeeCalculator($d['base_fee'], $d['per_km_fee'], $d['max_fee'], $d['free_over'], $currency);
        });

        // Contextual primitives (currency + verification policy).
        foreach ([EloquentVendorRepository::class, EloquentCartRepository::class, EloquentDeliveryRepository::class,
            VendorService::class, CartService::class, CheckoutService::class, VendorDashboardService::class,
            MenuManagementController::class] as $needsCurrency) {
            $this->app->when($needsCurrency)->needs('$currency')->give($currency);
        }
        $this->app->when(VendorService::class)->needs('$requireVerification')->give($requireVerification);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
    }
}
