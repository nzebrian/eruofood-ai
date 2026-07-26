<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Application\DTO\GatewaySettings;
use EruoFood\Ai\Application\DTO\GenerationDefaults;
use EruoFood\Ai\Application\Port\AiRateLimiter;
use EruoFood\Ai\Application\Port\AiResponseCache;
use EruoFood\Ai\Application\Port\CostCalculator;
use EruoFood\Ai\Application\Port\ProviderRegistry;
use EruoFood\Ai\Domain\Conversation\ConversationRepository;
use EruoFood\Ai\Domain\Prompt\PromptRepository;
use EruoFood\Ai\Domain\Usage\AiUsageLogRepository;
use EruoFood\Ai\Infrastructure\Cache\LaravelAiResponseCache;
use EruoFood\Ai\Infrastructure\Cache\NullAiResponseCache;
use EruoFood\Ai\Infrastructure\Cost\TableCostCalculator;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\EloquentAiUsageLogRepository;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\EloquentConversationRepository;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\EloquentPromptRepository;
use EruoFood\Ai\Infrastructure\RateLimit\LaravelAiRateLimiter;
use EruoFood\Ai\Infrastructure\RateLimit\NullAiRateLimiter;
use Illuminate\Cache\RateLimiter as LaravelLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the AI Engine bounded context.
 *
 * Wires every port to its adapter: the provider registry (multi-provider +
 * fallback), the response cache, the rate limiter, the cost calculator, the
 * gateway settings, and the Eloquent repositories. Toggling caching or rate
 * limiting swaps the real adapter for a null one, so the rest of the engine is
 * unaware of the choice. The gateway, feature services and controllers are then
 * auto-wired by the container from these bindings.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];
        /** @var array<string, mixed> $ai */
        $ai = $config->get('ai');

        $this->bindRepositories();
        $this->bindProviderRegistry($ai);
        $this->bindCrossCuttingAdapters($ai);
        $this->bindSettings($ai);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
    }

    private function bindRepositories(): void
    {
        $this->app->bind(PromptRepository::class, EloquentPromptRepository::class);
        $this->app->bind(ConversationRepository::class, EloquentConversationRepository::class);
        $this->app->bind(AiUsageLogRepository::class, EloquentAiUsageLogRepository::class);
    }

    /** @param array<string, mixed> $ai */
    private function bindProviderRegistry(array $ai): void
    {
        $this->app->singleton(ProviderRegistry::class, function (Application $app) use ($ai): ContainerProviderRegistry {
            /** @var array<string, array<string, mixed>> $providerConfig */
            $providerConfig = $ai['providers'];
            $timeout = (int) $ai['defaults']['timeout'];
            $http = $app->make(HttpClient::class);

            $providers = [
                'anthropic' => new AnthropicProvider($http, $providerConfig['anthropic'], $timeout),
                'openai' => new OpenAiProvider($http, $providerConfig['openai'], $timeout),
                'gemini' => new GeminiProvider($http, $providerConfig['gemini'], $timeout),
                'local' => new LocalLlmProvider($http, $providerConfig['local'], $timeout),
                'mock' => new MockProvider((string) ($providerConfig['mock']['model'] ?? 'mock-1')),
            ];

            /** @var list<string> $fallbacks */
            $fallbacks = $ai['fallbacks'];

            return new ContainerProviderRegistry($providers, (string) $ai['default'], $fallbacks);
        });
    }

    /** @param array<string, mixed> $ai */
    private function bindCrossCuttingAdapters(array $ai): void
    {
        /** @var array{pricing: array<string, array{input: float, output: float}>} $ai */
        $this->app->bind(CostCalculator::class, fn (): TableCostCalculator => new TableCostCalculator($ai['pricing']));

        $this->app->bind(AiResponseCache::class, function (Application $app) use ($ai): AiResponseCache {
            /** @var array{enabled: bool, store: ?string, prefix: string} $cache */
            $cache = $ai['cache'];
            if (! $cache['enabled']) {
                return new NullAiResponseCache();
            }

            return new LaravelAiResponseCache($app->make('cache')->store($cache['store']), $cache['prefix']);
        });

        $this->app->bind(AiRateLimiter::class, function (Application $app) use ($ai): AiRateLimiter {
            /** @var array{enabled: bool, max_requests: int, window_seconds: int} $limit */
            $limit = $ai['rate_limit'];
            if (! $limit['enabled']) {
                return new NullAiRateLimiter();
            }

            return new LaravelAiRateLimiter(
                $app->make(LaravelLimiter::class),
                $limit['max_requests'],
                $limit['window_seconds'],
            );
        });
    }

    /** @param array<string, mixed> $ai */
    private function bindSettings(array $ai): void
    {
        /** @var array{cache: array{enabled: bool, ttl: int}, retry: array{attempts: int, base_delay_ms: int}, defaults: array{max_tokens: int, temperature: float}} $ai */
        $this->app->instance(GatewaySettings::class, new GatewaySettings(
            cacheEnabled: (bool) $ai['cache']['enabled'],
            cacheTtlSeconds: (int) $ai['cache']['ttl'],
            retryAttempts: (int) $ai['retry']['attempts'],
            retryBaseDelayMs: (int) $ai['retry']['base_delay_ms'],
        ));

        $this->app->instance(GenerationDefaults::class, new GenerationDefaults(
            maxTokens: (int) $ai['defaults']['max_tokens'],
            temperature: (float) $ai['defaults']['temperature'],
        ));
    }
}
