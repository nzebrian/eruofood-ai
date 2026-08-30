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
use EruoFood\Search\Domain\Access\SearchScopeGate;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Capability\SearchCapability;
use EruoFood\Search\Domain\Document\Ranker;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\SavedSearch\SavedSearchRepository;
use EruoFood\Search\Infrastructure\Cache\LaravelSearchCache;
use EruoFood\Search\Infrastructure\Capability\SearchCapabilityProbe;
use EruoFood\Search\Infrastructure\Console\PurgeSearchQueryLogCommand;
use EruoFood\Search\Infrastructure\Console\ReindexSearchCommand;
use EruoFood\Search\Infrastructure\Embedding\HashingEmbeddingGenerator;
use EruoFood\Search\Infrastructure\Event\DomainEventSubscriber;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSavedSearchRepository;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSearchAnalyticsRepository;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSearchIndexRepository;
use EruoFood\Search\Infrastructure\Source\FoodSourceProvider;
use EruoFood\Search\Infrastructure\Source\ProductSourceProvider;
use EruoFood\Search\Infrastructure\Source\RecipeSourceProvider;
use EruoFood\Search\Infrastructure\Source\VendorSourceProvider;
use EruoFood\Search\Infrastructure\Understanding\AiQueryUnderstanding;
use EruoFood\Search\Infrastructure\Understanding\PassthroughQueryUnderstanding;
use EruoFood\Shared\Domain\Schedule\Cadence;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
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
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);
        $dims = (int) $config->get('search.embedding_dimensions', 64);
        $lexicalWeight = (float) $config->get('search.lexical_weight', 0.6);

        // Ranking + embedding.
        $this->app->singleton(Ranker::class, fn (): Ranker => new Ranker($lexicalWeight));
        $this->app->singleton(EmbeddingGenerator::class, fn (): EmbeddingGenerator => new HashingEmbeddingGenerator($dims));

        // Repositories.
        $this->app->singleton(SearchIndexRepository::class, fn ($app): SearchIndexRepository => new EloquentSearchIndexRepository(
            $app->make(Ranker::class),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.candidate_pool', 200),
            (bool) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.use_pgvector', true),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.max_result_window', 1000),
            // Bounded capability memo. This binding is a singleton and is held
            // by further singletons, and a queue worker is a long-lived
            // process — so the answer expires rather than being cached for the
            // life of the process (M38-VECTOR-001).
            (float) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.capability_ttl', 30),
        ));
        $this->app->bind(SavedSearchRepository::class, EloquentSavedSearchRepository::class);
        $this->app->bind(SearchAnalyticsRepository::class, EloquentSearchAnalyticsRepository::class);

        // Query understanding — AI-backed when enabled and available, else offline.
        $this->app->bind(QueryUnderstanding::class, function ($app): QueryUnderstanding {
            if ((bool) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.ai_understanding', false) && $app->bound(AiAdvisor::class)) {
                return new AiQueryUnderstanding($app->make(AiAdvisor::class));
            }

            return new PassthroughQueryUnderstanding();
        });

        // Result cache (M38-CACHE-001). Optionally a dedicated store; always a
        // Search-owned namespace, and never a whole-store clear.
        $this->app->singleton(SearchCache::class, function ($app): SearchCache {
            $cfg = $app->make(\Illuminate\Contracts\Config\Repository::class);
            $store = $cfg->get('search.cache_store');

            $repository = is_string($store) && $store !== ''
                ? $app->make(\Illuminate\Contracts\Cache\Factory::class)->store($store)
                : $app->make(CacheRepository::class);

            return new LaravelSearchCache($repository, (string) $cfg->get('search.cache_prefix', 'eruofood:search'));
        });

        // The single scope-authorisation decision (M38-SEC-001).
        $this->app->singleton(SearchScopeGate::class, fn (): SearchScopeGate => new SearchScopeGate());

        // Database capability, probed against the live connection (M38-DB-001,
        // M38-VECTOR-001). Not a singleton of the RESULT — the probe object is
        // shared, the answer is re-derived, so a capability restored at runtime
        // is not reported as permanently missing.
        $this->app->singleton(SearchCapabilityProbe::class, function ($app): SearchCapabilityProbe {
            $cfg = $app->make(\Illuminate\Contracts\Config\Repository::class);

            $connection = $app->make(\Illuminate\Database\DatabaseManager::class)->connection();

            return new SearchCapabilityProbe(
                $connection,
                $connection->getDriverName(),
                (bool) $cfg->get('search.vector_enabled', true) && (bool) $cfg->get('search.use_pgvector', true),
                (bool) $cfg->get('search.trgm_enabled', true),
            );
        });
        $this->app->bind(SearchCapability::class, fn ($app): SearchCapability => $app->make(SearchCapabilityProbe::class)->probe());

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
                $app->make(\Psr\Log\LoggerInterface::class),
            );
        });

        // Query builder (synonyms + optional AI expansion + pagination clamping).
        $this->app->singleton(QueryBuilder::class, function ($app): QueryBuilder {
            $cfg = $app->make(\Illuminate\Contracts\Config\Repository::class);
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
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.cache_ttl', 120),
            $app->make(SearchScopeGate::class),
        ));

        // Autocomplete / suggestions.
        $this->app->singleton(AutocompleteService::class, fn ($app): AutocompleteService => new AutocompleteService(
            $app->make(SearchIndexRepository::class),
            $app->make(SearchAnalyticsRepository::class),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.suggestion_limit', 8),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.trending_days', 7),
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.recent_limit', 10),
            $app->make(SearchScopeGate::class),
            // M39-SEC-001: how many times a term must have been searched on a
            // public scope before it may be shown to an anonymous caller.
            (int) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.public_term_min_occurrences', 3),
        ));

        // Event → index translator.
        $this->app->bind(EventIndexTranslator::class, function ($app): EventIndexTranslator {
            /** @var array<string, array{type: string, id_field: string}> $map */
            $map = (array) $app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.index_events', []);

            $cfg = $app->make(\Illuminate\Contracts\Config\Repository::class);
            /** @var list<int> $backoff */
            $backoff = (array) $cfg->get('search.index_job_backoff', [10, 30, 120, 300]);

            return new EventIndexTranslator(
                $app->make(SearchIndexManager::class),
                $map,
                $app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
                (bool) $cfg->get('search.async_indexing', true),
                (string) $cfg->get('search.queue', 'search'),
                (int) $cfg->get('search.index_job_tries', 5),
                (int) $cfg->get('search.index_job_timeout', 120),
                $backoff,
            );
        });

        $this->commands([ReindexSearchCommand::class, PurgeSearchQueryLogCommand::class]);

        $this->registerScheduledWork();
    }

    /**
     * Recurring work Search owns, described rather than scheduled (M40-SEC-001).
     *
     * ## Why this ships DISABLED
     *
     * `ScheduledTask` requires `enabled` explicitly precisely so this decision
     * is visible, and every task currently in the registry — both of Payments'
     * — is registered off. A retention purge is an irreversible, unattended
     * delete against production data; switching it on is an operator decision
     * about a specific database, not a default somebody inherits by upgrading.
     *
     * Until an operator enables it, `search:purge-query-log` is a command they
     * run when they choose. The retention policy is declared either way, so
     * `RetentionRegistry` states the intent honestly and the gap between
     * declared and enforced is visible rather than assumed away.
     *
     * To enable: register this task with `enabled: true`, confirm the window in
     * `search.query_log_retention_days`, and run `--dry-run` first.
     */
    private function registerScheduledWork(): void
    {
        $this->app->make(ScheduleRegistry::class)->register(ScheduledTask::of(
            name: 'search:purge-query-log',
            command: 'search:purge-query-log',
            cadence: Cadence::Daily,
            enabled: false,
            description: 'Deletes search query-log rows past the retention window in '
                .'search.query_log_retention_days. Destroys rows; prints counts only, never terms or user ids. '
                .'Disabled by default — enabling an unattended irreversible delete is an operator decision.',
            destructiveRetention: true,
        ));
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        // Subscribe to published domain events (the only inbound coupling).
        /** @var array<string, array{type: string, id_field: string}> $map */
        $map = (array) $this->app->make(\Illuminate\Contracts\Config\Repository::class)->get('search.index_events', []);
        (new DomainEventSubscriber($this->app->make(Dispatcher::class), $map))->register();
    }
}
