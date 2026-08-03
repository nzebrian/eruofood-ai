<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Provider;

use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\PublicApi\Application\Port\QuotaStore;
use EruoFood\PublicApi\Application\Port\RateLimiter;
use EruoFood\PublicApi\Application\Port\SecretHasher;
use EruoFood\PublicApi\Application\Port\WebhookDispatcher;
use EruoFood\PublicApi\Application\Port\WebhookUrlGuard;
use EruoFood\PublicApi\Application\Service\ApiKeyService;
use EruoFood\PublicApi\Application\Service\ApplicationService;
use EruoFood\PublicApi\Application\Service\DeveloperService;
use EruoFood\PublicApi\Application\Service\QuotaService;
use EruoFood\PublicApi\Application\Service\RateLimitService;
use EruoFood\PublicApi\Application\Service\ScopeRegistry;
use EruoFood\PublicApi\Application\Service\WebhookService;
use EruoFood\PublicApi\Application\Service\WebhookSigner;
use EruoFood\PublicApi\Domain\ApiKey\ApiKeyRepository;
use EruoFood\PublicApi\Domain\Application\ApplicationRepository;
use EruoFood\PublicApi\Domain\Developer\DeveloperRepository;
use EruoFood\PublicApi\Domain\Read\CatalogReadPort;
use EruoFood\PublicApi\Domain\Webhook\WebhookRepository;
use EruoFood\PublicApi\Infrastructure\Console\DispatchWebhookRetriesCommand;
use EruoFood\PublicApi\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentApiKeyRepository;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentApplicationRepository;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentDeveloperRepository;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentWebhookRepository;
use EruoFood\PublicApi\Infrastructure\RateLimit\CacheQuotaStore;
use EruoFood\PublicApi\Infrastructure\RateLimit\CacheRateLimiter;
use EruoFood\PublicApi\Infrastructure\Read\CatalogReadAdapter;
use EruoFood\PublicApi\Infrastructure\Security\Sha256SecretHasher;
use EruoFood\PublicApi\Infrastructure\Webhook\HttpWebhookDispatcher;
use EruoFood\PublicApi\Infrastructure\Webhook\NetworkWebhookUrlGuard;
use EruoFood\PublicApi\Interface\Http\Middleware\ApiQuota;
use EruoFood\PublicApi\Interface\Http\Middleware\ApiRateLimit;
use EruoFood\PublicApi\Interface\Http\Middleware\ApiRequestContext;
use EruoFood\PublicApi\Interface\Http\Middleware\AuthenticatePublicApi;
use EruoFood\PublicApi\Interface\Http\Middleware\EnforceScope;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Public API & Developer Platform. It wires the
 * developer-platform repositories and services, the security/rate-limit/quota
 * adapters, the webhook system, and the read-port façade over Catalog; registers
 * the public-gateway middleware stack; mounts the public + portal routes; and
 * subscribes the webhook fan-out to internal domain events. The Public API is a
 * separate, controlled surface — no internal endpoint is exposed here.
 */
final class PublicApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);

        $this->app->bind(DeveloperRepository::class, EloquentDeveloperRepository::class);
        $this->app->bind(ApplicationRepository::class, EloquentApplicationRepository::class);
        $this->app->bind(ApiKeyRepository::class, EloquentApiKeyRepository::class);
        $this->app->bind(WebhookRepository::class, EloquentWebhookRepository::class);

        // OAuth2 persistence.
        $this->app->bind(\EruoFood\PublicApi\Domain\OAuth\OAuthClientRepository::class, \EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentOAuthClientRepository::class);
        $this->app->bind(\EruoFood\PublicApi\Domain\OAuth\AccessTokenRepository::class, \EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentAccessTokenRepository::class);
        $this->app->bind(\EruoFood\PublicApi\Domain\OAuth\RefreshTokenRepository::class, \EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentRefreshTokenRepository::class);
        $this->app->bind(\EruoFood\PublicApi\Domain\OAuth\AuthorizationCodeRepository::class, \EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\EloquentAuthorizationCodeRepository::class);

        $this->app->bind(SecretHasher::class, Sha256SecretHasher::class);
        $this->app->singleton(WebhookSigner::class);

        // SSRF/egress guard for webhook destinations (registration + send time).
        $this->app->singleton(WebhookUrlGuard::class, function ($app): WebhookUrlGuard {
            /** @var array<string, mixed> $sec */
            $sec = (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.webhooks.security', []);

            return new NetworkWebhookUrlGuard(
                allowedSchemes: array_map('strtolower', (array) ($sec['allowed_schemes'] ?? ['https'])),
                enforceHttps: (bool) ($sec['enforce_https'] ?? true),
                allowedPorts: array_map('intval', (array) ($sec['allowed_ports'] ?? [443, 80])),
                blockPrivateNetworks: (bool) ($sec['block_private_networks'] ?? true),
                allowedHosts: (array) ($sec['allowed_hosts'] ?? []),
            );
        });

        $this->app->bind(WebhookDispatcher::class, function ($app): WebhookDispatcher {
            /** @var array<string, mixed> $sec */
            $sec = (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.webhooks.security', []);

            return new HttpWebhookDispatcher(
                $app->make(WebhookUrlGuard::class),
                (int) ($sec['connect_timeout_seconds'] ?? 5),
                (int) ($sec['max_response_bytes'] ?? 65536),
            );
        });

        $storeName = (string) $config->get('publicapi.counter_store', 'array');
        $this->app->singleton(RateLimiter::class, fn ($app): RateLimiter => new CacheRateLimiter($app['cache']->store($storeName)));
        $this->app->singleton(QuotaStore::class, fn ($app): QuotaStore => new CacheQuotaStore($app['cache']->store($storeName)));

        // Read façade over Catalog (the one sanctioned cross-context read seam).
        $this->app->bind(CatalogReadPort::class, fn ($app): CatalogReadPort => new CatalogReadAdapter(
            $app->make(FoodRepository::class),
            $app->make(RecipeRepository::class),
        ));

        // Orders — delegate to the Commerce Order domain (never bypassed).
        $this->app->bind(\EruoFood\PublicApi\Domain\Order\OrderPort::class, fn ($app): \EruoFood\PublicApi\Domain\Order\OrderPort => new \EruoFood\PublicApi\Infrastructure\Order\CommerceOrderAdapter(
            $app->make(\EruoFood\Commerce\Application\Service\OrderService::class),
            $app->make(\EruoFood\Commerce\Application\Service\CheckoutService::class),
        ));

        // Read façades over the other contexts (restaurants, products, nutrition, search).
        $this->app->bind(\EruoFood\PublicApi\Domain\Read\RestaurantReadPort::class, \EruoFood\PublicApi\Infrastructure\Read\MarketplaceReadAdapter::class);
        $this->app->bind(\EruoFood\PublicApi\Domain\Read\CommerceReadPort::class, \EruoFood\PublicApi\Infrastructure\Read\CommerceReadAdapter::class);
        $this->app->bind(\EruoFood\PublicApi\Domain\Read\NutritionReadPort::class, \EruoFood\PublicApi\Infrastructure\Read\NutritionReadAdapter::class);
        $this->app->bind(\EruoFood\PublicApi\Domain\Read\SearchReadPort::class, \EruoFood\PublicApi\Infrastructure\Read\SearchReadAdapter::class);

        $this->app->singleton(ScopeRegistry::class, fn ($app): ScopeRegistry => new ScopeRegistry(
            (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.scopes', []),
        ));

        $this->app->singleton(ApplicationService::class);
        $this->app->singleton(DeveloperService::class);

        $this->app->singleton(ApiKeyService::class, fn ($app): ApiKeyService => new ApiKeyService(
            $app->make(ApiKeyRepository::class),
            $app->make(ApplicationRepository::class),
            $app->make(SecretHasher::class),
            $app->make(EventBus::class),
            (string) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.key.prefix', 'efk'),
            (string) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.key.environment_tag', 'live'),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.key.secret_bytes', 32),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.key.default_ttl_days', 0),
        ));

        // OAuth2 authorization server + the authentication resolver chain. The
        // chain resolves either an API key or an OAuth2 bearer token to the same
        // authenticated context, so the gateway is agnostic to the mechanism.
        $this->app->singleton(\EruoFood\PublicApi\Application\Service\OAuthService::class, fn ($app): \EruoFood\PublicApi\Application\Service\OAuthService => new \EruoFood\PublicApi\Application\Service\OAuthService(
            $app->make(\EruoFood\PublicApi\Domain\OAuth\OAuthClientRepository::class),
            $app->make(\EruoFood\PublicApi\Domain\OAuth\AccessTokenRepository::class),
            $app->make(\EruoFood\PublicApi\Domain\OAuth\RefreshTokenRepository::class),
            $app->make(\EruoFood\PublicApi\Domain\OAuth\AuthorizationCodeRepository::class),
            $app->make(SecretHasher::class),
            (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.oauth', []),
        ));

        $this->app->singleton(\EruoFood\PublicApi\Interface\Http\Middleware\AuthenticatePublicApi::class, fn ($app): \EruoFood\PublicApi\Interface\Http\Middleware\AuthenticatePublicApi => new \EruoFood\PublicApi\Interface\Http\Middleware\AuthenticatePublicApi([
            $app->make(\EruoFood\PublicApi\Application\Auth\ApiKeyPrincipalResolver::class),
            $app->make(\EruoFood\PublicApi\Application\Auth\OAuthPrincipalResolver::class),
        ]));

        $this->app->singleton(RateLimitService::class, fn ($app): RateLimitService => new RateLimitService(
            $app->make(RateLimiter::class),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.rate_limit.per_minute', 120),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.rate_limit.burst', 40),
            (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.rate_limit.endpoints', []),
        ));

        $this->app->singleton(QuotaService::class, fn ($app): QuotaService => new QuotaService(
            $app->make(QuotaStore::class),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.quota.daily', 10000),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.quota.monthly', 200000),
        ));

        $this->app->singleton(WebhookService::class, fn ($app): WebhookService => new WebhookService(
            $app->make(WebhookRepository::class),
            $app->make(WebhookDispatcher::class),
            $app->make(WebhookSigner::class),
            $app->make(EventBus::class),
            $app->make(WebhookUrlGuard::class),
            (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.webhooks', []),
        ));

        // The context middleware needs config scalars — bind it explicitly.
        $this->app->singleton(ApiRequestContext::class, fn ($app): ApiRequestContext => new ApiRequestContext(
            $app->make(EventBus::class),
            (string) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.current_version', 'v1'),
            (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.deprecated', []),
        ));

        $this->commands([DispatchWebhookRetriesCommand::class]);
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('publicapi.auth', AuthenticatePublicApi::class);
        $router->aliasMiddleware('publicapi.scope', EnforceScope::class);
        $router->aliasMiddleware('publicapi.ratelimit', ApiRateLimit::class);
        $router->aliasMiddleware('publicapi.quota', ApiQuota::class);
        $router->aliasMiddleware('publicapi.context', ApiRequestContext::class);

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Fan internal domain events out to subscribed webhooks (one-way, by name).
        /** @var array<string, string> $map */
        $map = (array) $this->app->make(\Illuminate\Contracts\Config\Repository::class)->get('publicapi.webhooks.events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
