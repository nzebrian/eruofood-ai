<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Registered service providers.
|------------------------------------------------------------------------------
| The application provider wires cross-cutting concerns. Each bounded context
| registers itself through its own module provider — add new modules here as
| they are introduced. This list is the single index of active modules.
*/

return [
    App\Providers\AppServiceProvider::class,

    // ---- Bounded context / foundation modules ----
    EruoFood\Shared\Infrastructure\Provider\SharedServiceProvider::class,
    EruoFood\Platform\Infrastructure\Provider\PlatformServiceProvider::class,
    EruoFood\Identity\Infrastructure\Provider\IdentityServiceProvider::class,
    EruoFood\Catalog\Infrastructure\Provider\CatalogServiceProvider::class,
    EruoFood\Ai\Infrastructure\Provider\AiServiceProvider::class,
    EruoFood\Nutrition\Infrastructure\Provider\NutritionServiceProvider::class,
    EruoFood\Marketplace\Infrastructure\Provider\MarketplaceServiceProvider::class,
    EruoFood\Commerce\Infrastructure\Provider\CommerceServiceProvider::class,
    EruoFood\Payments\Infrastructure\Provider\PaymentsServiceProvider::class,
    EruoFood\Notifications\Infrastructure\Provider\NotificationsServiceProvider::class,
    EruoFood\Analytics\Infrastructure\Provider\AnalyticsServiceProvider::class,
    EruoFood\Admin\Infrastructure\Provider\AdminServiceProvider::class,
];
