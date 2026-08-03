<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Provider;

use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\QueryUnderstanding;
use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Application\Service\AutocompleteService;
use EruoFood\Search\Application\Service\EventIndexTranslator;
use EruoFood\Search\Application\Service\QueryBuilder;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Application\Service\SearchService;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Document\Ranker;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\SavedSearch\SavedSearchRepository;
use EruoFood\Search\Infrastructure\Cache\LaravelSearchCache;
use EruoFood\Search\Infrastructure\Console\ReindexSearchCommand;
use EruoFood\Search\Infrastructure\Embedding\HashingEmbeddingGenerator;
use EruoFood\Search\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSearchAnalyticsRepository;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSavedSearchRepository;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSearchIndexRepository;
use EruoFood\Search\Infrastructure\Source\FoodSourceProvider;
use EruoFood\Search\Infrastructure\Source\ProductSourceProvider;
use EruoFood\Search\Infrastructure\Source\RecipeSourceProvider;
use EruoFood\Search\Infrastructure\Source\VendorSourceProvider;
use EruoFood\Search\Infrastructure\Understanding\AiQueryUnderstanding;
use EruoFood\Search\Infrastructure\Understanding\PassthroughQueryUnderstanding;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the Search, Discovery & Recommendation Engine. Binds the
 * index/saved-search/analytics repositories, the embedder and query-understanding
 * adapters, the read-only source providers, the search pipeline and the
 * recommendation engine; registers the reindex command; and subscribes the index
 * to published domain events — the only inbound coupling, one-way and by name.
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];
        $dims = (int) $config->get('search.embedding_dimensions', 64);
        $lexicalWeight = (float) $config->get('search.lexical_weight', 0.6);

        // Ranking + embedding.
        $this->app->singleton(Ranker::class, fn (): Ranker => new Ranker($lexicalWeight));
        $this->app->singleton(EmbeddingGenerator::class, fn (): EmbeddingGenerator => new HashingEmbeddingGenerator($dims));

        // Repositories.
        $this->app->singleton(SearchIndexRepository::class, fn ($app): SearchIndexRepository => new EloquentSearchIndexRepository(
            $app->make(Ranker::class),
            $lexicalWeight,
            (int) $app['config']->get('search.candidate_pool', 200),
            (bool) $app['config']->get('search.use_pgvector', true),
        ));
        $this->app->bind(SavedSearchRepository::class, EloquentSavedSearchRepository::class);
        $this->app->bind(SearchAnalyticsRepository::class, EloquentSearchAnalyticsRepository::class);

        // Query understanding — AI-backed when enabled and available, else offline.
        $this->app->bind(QueryUnderstanding::class, function ($app): QueryUnderstanding {
            if ((bool) $app['config']->get('search.ai_understanding', false) && $app->bound(AiAdvisor::class)) {
                return new AiQueryUnderstanding($app->make(AiAdvisor::class));
            }

            return new PassthroughQueryUnderstanding();
        });

        // Result cache.
        $this->app->singleton(SearchCache::class, fn ($app): SearchCache => new LaravelSearchCache($app->make(CacheRepository::class)));

        // Read-only source providers (one per indexed type).
        $this->app->singleton(SearchIndexManager::class, function ($app): SearchIndexManager {
            $db = $app->make(ConnectionInterface::class);

            return new SearchIndexManager(
                $app->make(SearchIndexRepository::class),
                $app->make(EmbeddingGenerator::class),
                $app->make(SearchCache::class),
                [
                    'food' => new FoodSourceProvider($db),
                    'recipe' => new RecipeSourceProvider($db),
                    'product' => new ProductSourceProvider($db),
                    'vendor' => new VendorSourceProvider($db),
                ],
            );
        });

        // Query builder (synonyms + optional AI expansion + pagination clamping).
        $this->app->singleton(QueryBuilder::class, function ($app): QueryBuilder {
            $cfg = $app['config'];
            /** @var list<list<string>> $synonyms */
            $synonyms = (array) $cfg->get('search.synonyms', []);

            return new QueryBuilder(
                $app->make(QueryUnderstanding::class),
                $synonyms,
                (int) $cfg->get('search.per_page', 20),
                (int) $cfg->get('search.max_per_page', 100),
                (bool) $cfg->get('search.ai_understanding', false),
            );
        });

        // The pipeline.
        $this->app->singleton(SearchService::class, fn ($app): SearchService => new SearchService(
            $app->make(SearchIndexRepository::class),
            $app->make(EmbeddingGenerator::class),
            $app->make(SearchAnalyticsRepository::class),
            $app->make(SearchCache::class),
            (int) $app['config']->get('search.cache_ttl', 120),
        ));

        // Autocomplete / suggestions.
        $this->app->singleton(AutocompleteService::class, fn ($app): AutocompleteService => new AutocompleteService(
            $app->make(SearchIndexRepository::class),
            $app->make(SearchAnalyticsRepository::class),
            (int) $app['config']->get('search.suggestion_limit', 8),
            (int) $app['config']->get('search.trending_days', 7),
            (int) $app['config']->get('search.recent_limit', 10),
        ));

        // Event → index translator.
        $this->app->bind(EventIndexTranslator::class, function ($app): EventIndexTranslator {
            /** @var array<string, array{type: string, id_field: string}> $map */
            $map = (array) $app['config']->get('search.index_events', []);

            return new EventIndexTranslator($app->make(SearchIndexManager::class), $map);
        });

        $this->commands([ReindexSearchCommand::class]);
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe to published domain events (the only inbound coupling).
        /** @var array<string, array{type: string, id_field: string}> $map */
        $map = (array) $this->app['config']->get('search.index_events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
