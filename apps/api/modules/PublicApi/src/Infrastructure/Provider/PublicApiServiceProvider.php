<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Provider;

use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\PublicApi\Application\Port\QuotaStore;
use EruoFood\PublicApi\Application\Port\RateLimiter;
use EruoFood\PublicApi\Application\Port\SecretHasher;
use EruoFood\PublicApi\Application\Port\WebhookDispatcher;
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
use EruoFood\PublicApi\Interface\Http\Middleware\ApiQuota;
use EruoFood\PublicApi\Interface\Http\Middleware\ApiRateLimit;
use EruoFood\PublicApi\Interface\Http\Middleware\ApiRequestContext;
use EruoFood\PublicApi\Interface\Http\Middleware\AuthenticateApiKey;
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
        $config = $this->app['config'];

        $this->app->bind(DeveloperRepository::class, EloquentDeveloperRepository::class);
        $this->app->bind(ApplicationRepository::class, EloquentApplicationRepository::class);
        $this->app->bind(ApiKeyRepository::class, EloquentApiKeyRepository::class);
        $this->app->bind(WebhookRepository::class, EloquentWebhookRepository::class);

        $this->app->bind(SecretHasher::class, Sha256SecretHasher::class);
        $this->app->bind(WebhookDispatcher::class, HttpWebhookDispatcher::class);
        $this->app->singleton(WebhookSigner::class);

        $storeName = (string) $config->get('publicapi.counter_store', 'array');
        $this->app->singleton(RateLimiter::class, fn ($app): RateLimiter => new CacheRateLimiter($app['cache']->store($storeName)));
        $this->app->singleton(QuotaStore::class, fn ($app): QuotaStore => new CacheQuotaStore($app['cache']->store($storeName)));

        // Read façade over Catalog (the one sanctioned cross-context read seam).
        $this->app->bind(CatalogReadPort::class, fn ($app): CatalogReadPort => new CatalogReadAdapter(
            $app->make(FoodRepository::class),
            $app->make(RecipeRepository::class),
        ));

        $this->app->singleton(ScopeRegistry::class, fn ($app): ScopeRegistry => new ScopeRegistry(
            (array) $app['config']->get('publicapi.scopes', []),
        ));

        $this->app->singleton(ApplicationService::class);
        $this->app->singleton(DeveloperService::class);

        $this->app->singleton(ApiKeyService::class, fn ($app): ApiKeyService => new ApiKeyService(
            $app->make(ApiKeyRepository::class),
            $app->make(ApplicationRepository::class),
            $app->make(SecretHasher::class),
            $app->make(EventBus::class),
            (string) $app['config']->get('publicapi.key.prefix', 'efk'),
            (string) $app['config']->get('publicapi.key.environment_tag', 'live'),
            (int) $app['config']->get('publicapi.key.secret_bytes', 32),
            (int) $app['config']->get('publicapi.key.default_ttl_days', 0),
        ));

        $this->app->singleton(RateLimitService::class, fn ($app): RateLimitService => new RateLimitService(
            $app->make(RateLimiter::class),
            (int) $app['config']->get('publicapi.rate_limit.per_minute', 120),
            (int) $app['config']->get('publicapi.rate_limit.burst', 40),
            (array) $app['config']->get('publicapi.rate_limit.endpoints', []),
        ));

        $this->app->singleton(QuotaService::class, fn ($app): QuotaService => new QuotaService(
            $app->make(QuotaStore::class),
            (int) $app['config']->get('publicapi.quota.daily', 10000),
            (int) $app['config']->get('publicapi.quota.monthly', 200000),
        ));

        $this->app->singleton(WebhookService::class, fn ($app): WebhookService => new WebhookService(
            $app->make(WebhookRepository::class),
            $app->make(WebhookDispatcher::class),
            $app->make(WebhookSigner::class),
            $app->make(EventBus::class),
            (array) $app['config']->get('publicapi.webhooks', []),
        ));

        // The context middleware needs config scalars — bind it explicitly.
        $this->app->singleton(ApiRequestContext::class, fn ($app): ApiRequestContext => new ApiRequestContext(
            $app->make(EventBus::class),
            (string) $app['config']->get('publicapi.current_version', 'v1'),
            (array) $app['config']->get('publicapi.deprecated', []),
        ));

        $this->commands([DispatchWebhookRetriesCommand::class]);
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('publicapi.auth', AuthenticateApiKey::class);
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
        $map = (array) $this->app['config']->get('publicapi.webhooks.events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
