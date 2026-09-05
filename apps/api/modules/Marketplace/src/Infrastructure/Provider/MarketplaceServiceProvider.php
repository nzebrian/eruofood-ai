<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Provider;

use EruoFood\Geo\Contracts\DeliveryDistanceProvider;
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
use EruoFood\Marketplace\Infrastructure\Delivery\RoutedDeliveryFeeCalculator;
use EruoFood\Marketplace\Infrastructure\Delivery\ZoneDeliveryFeeCalculator;
use EruoFood\Marketplace\Infrastructure\Event\VerificationProjectionSubscriber;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentCartRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentMenuCategoryRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentMenuItemRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentOrderRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentRiderRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentVendorRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\EloquentVendorReviewRepository;
use EruoFood\Marketplace\Infrastructure\Seeder\MarketplaceSeeder;
use EruoFood\Marketplace\Interface\Http\Controller\MenuManagementController;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

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
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);
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
        /*
        | Delivery pricing, with M25's routed distance behind a switch.
        |
        | The routed calculator *wraps* the straight-line one rather than
        | replacing it, and with `delivery.routing_pricing.enabled` false it
        | delegates every quote unchanged. That is what makes the change
        | reversible: turning routed pricing on or off is a configuration edit
        | with no deploy and no data migration.
        |
        | It matters because the wrapped calculator charges per kilometre of
        | straight-line distance, which in Lagos understates the real road
        | journey by 30–60% — in one direction, on every order. Correcting that
        | raises real prices, so it is a deliberate act rather than a
        | deployment side effect.
        */
        $this->app->bind(DeliveryFeeCalculator::class, function ($app) use ($config, $currency): DeliveryFeeCalculator {
            /** @var array{base_fee: int, per_km_fee: int, max_fee: int, free_over: int} $d */
            $d = $config->get('marketplace.delivery');

            $legacy = new ZoneDeliveryFeeCalculator($d['base_fee'], $d['per_km_fee'], $d['max_fee'], $d['free_over'], $currency);

            return new RoutedDeliveryFeeCalculator(
                legacy: $legacy,
                distances: $app->make(DeliveryDistanceProvider::class),
                logger: $app->make(LoggerInterface::class),
                baseFee: $d['base_fee'],
                perKmFee: $d['per_km_fee'],
                maxFee: $d['max_fee'],
                freeOver: $d['free_over'],
                currency: $currency,
                routedPricingEnabled: (bool) $config->get('delivery.routing_pricing.enabled', false),
                shadowMode: (bool) $config->get('delivery.routing_pricing.shadow_mode', false),
                refuseWhenUnavailable: (bool) $config->get('delivery.routing_pricing.refuse_when_unavailable', true),
            );
        });

        // Contextual primitives (currency + verification policy).
        foreach ([EloquentVendorRepository::class, EloquentCartRepository::class, EloquentDeliveryRepository::class,
            VendorService::class, CartService::class, CheckoutService::class, VendorDashboardService::class,
            MenuManagementController::class, MarketplaceSeeder::class] as $needsCurrency) {
            $this->app->when($needsCurrency)->needs('$currency')->give($currency);
        }
        $this->app->when(VendorService::class)->needs('$requireVerification')->give(fn () => $requireVerification);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Keep the local eligibility projection in step with Verification. One-way,
        // by event name — this module never queries the Verification context.
        (new VerificationProjectionSubscriber())->register($this->app->make(Dispatcher::class));
    }
}
