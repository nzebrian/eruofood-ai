<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Search, Discovery & Recommendation Engine configuration
|------------------------------------------------------------------------------
| The Search context owns its own inverted index + vector store. No business
| module searches its own tables — they publish domain events, and Search
| reindexes the affected document by reading the source row (a soft reference).
| All querying happens against Search's own index tables.
*/

return [
    // Embedding dimensionality for the semantic (pgvector) vectors. The hashing
    // embedder and the migration's vector column both read this.
    'embedding_dimensions' => (int) env('SEARCH_EMBEDDING_DIMS', 64),

    // Prefer native pgvector when the connection is Postgres and the extension
    // is installed; otherwise a portable PHP cosine re-rank is used (e.g. in the
    // sqlite test suite). Set to false to force the portable path everywhere.
    'use_pgvector' => (bool) env('SEARCH_USE_PGVECTOR', true),

    /*
    |--------------------------------------------------------------------------
    | Result cache (M38-CACHE-001)
    |--------------------------------------------------------------------------
    | Search NEVER flushes the shared application cache. Invalidation works by
    | bumping a Search-owned version counter, so every cached result key becomes
    | unreachable at once while every other key in the store is untouched. That
    | costs one INCR instead of a store-wide FLUSH, which is what made a full
    | backfill previously issue one global flush per document.
    |
    | `cache_store` optionally routes Search at a dedicated store; null uses the
    | default store, still namespaced and still never cleared wholesale.
    */
    'cache_ttl' => (int) env('SEARCH_CACHE_TTL', 120),
    'cache_store' => env('SEARCH_CACHE_STORE'),
    'cache_prefix' => env('SEARCH_CACHE_PREFIX', 'eruofood:search'),

    // Default and maximum page sizes.
    'per_page' => (int) env('SEARCH_PER_PAGE', 20),
    'max_per_page' => (int) env('SEARCH_MAX_PER_PAGE', 100),

    // How many lexical candidates to pull before the vector re-rank.
    'candidate_pool' => (int) env('SEARCH_CANDIDATE_POOL', 200),

    /*
    |--------------------------------------------------------------------------
    | Ranking window (M38-SEARCH-001)
    |--------------------------------------------------------------------------
    | Sorts that PostgreSQL can express (popularity, rating, price, …) are
    | paginated in SQL with LIMIT/OFFSET and are exact at any depth.
    |
    | Relevance and distance are blended in PHP, so they need a materialised
    | window. This is its hard bound. Asking for a page beyond it is answered
    | with an explicit error, never an empty page — the previous behaviour
    | silently returned nothing past the 200-row candidate pool while still
    | reporting that more results existed.
    */
    'max_result_window' => (int) env('SEARCH_MAX_RESULT_WINDOW', 1000),

    // Blend of lexical vs. semantic score in the final ranking (0..1). Higher
    // weights lexical/full-text relevance; the remainder weights vector cosine.
    'lexical_weight' => (float) env('SEARCH_LEXICAL_WEIGHT', 0.6),

    // Whether to route the query string through the AI understanding adapter
    // (intent/expansion). Off by default so the default path stays offline.
    'ai_understanding' => (bool) env('SEARCH_AI_UNDERSTANDING', false),

    /*
    |--------------------------------------------------------------------------
    | Asynchronous indexing (M38-QUEUE-001)
    |--------------------------------------------------------------------------
    | Default ON. Domain events enqueue a job instead of hydrating the source,
    | embedding it, upserting and invalidating the cache on the publishing
    | request thread.
    |
    | Turning this off restores the OLD synchronous behaviour. It exists for
    | local debugging and for a controlled rollback, and it is NOT a way to make
    | tests pass: `SearchAsyncIndexingTest` asserts the default is async, so
    | shipping with it disabled fails the suite rather than hiding the defect.
    */
    'async_indexing' => (bool) env('SEARCH_ASYNC_INDEXING', true),
    'queue' => env('SEARCH_QUEUE', 'search'),
    'index_job_tries' => (int) env('SEARCH_INDEX_JOB_TRIES', 5),
    'index_job_timeout' => (int) env('SEARCH_INDEX_JOB_TIMEOUT', 120),
    'index_job_backoff' => [10, 30, 120, 300],

    /*
    |--------------------------------------------------------------------------
    | Database capability (M38-DB-001, M38-VECTOR-001)
    |--------------------------------------------------------------------------
    | `vector_enabled` and `trgm_enabled` express INTENT, never fact. Whether
    | the extension and its index actually exist is answered by
    | SearchCapabilityProbe against the live connection, and the answer is
    | reported honestly — including "probe failed", which is not the same as
    | "unavailable" and must never be rounded down to healthy.
    */
    'vector_enabled' => (bool) env('SEARCH_VECTOR_ENABLED', true),
    'trgm_enabled' => (bool) env('SEARCH_FTS_ENABLED', true),

    /*
    | How long the index repository may reuse a probed capability answer.
    |
    | The repository is a container singleton held by further singletons. Under
    | PHP-FPM that is request-scoped, but a QUEUE WORKER is a long-lived
    | process: a worker started before the acceleration migration provisioned
    | `vector` would otherwise cache "absent" for its whole life and never write
    | the vector column again. Bounded, not permanent — set to 0 to re-probe on
    | every call.
    */
    'capability_ttl' => (float) env('SEARCH_CAPABILITY_TTL', 30),

    // Autocomplete suggestion count and trending window (days).
    'suggestion_limit' => (int) env('SEARCH_SUGGESTION_LIMIT', 8),
    'trending_days' => (int) env('SEARCH_TRENDING_DAYS', 7),
    'recent_limit' => (int) env('SEARCH_RECENT_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | Public analytics suppression (M39-SEC-001)
    |--------------------------------------------------------------------------
    | `/search/trending` and `/search/suggestions` are PUBLIC and serve terms
    | other people typed. Two rules make a term publishable: it must have been
    | searched on a scope the public may search (admin-only scopes are excluded
    | in SQL), and it must have been searched at least this many times.
    |
    | A term below the threshold is withheld entirely, so a phrase one person
    | searched once is never broadcast. This is privacy SUPPRESSION, not
    | anonymity — a term repeated often enough by a single determined user still
    | qualifies, and raw query strings remain sensitive data. Retention of the
    | query log is tracked separately as M39-SEC-003 and is NOT solved here.
    |
    | Lowering this to 1 restores the pre-M39 behaviour and re-opens the leak.
    */
    'public_term_min_occurrences' => (int) env('SEARCH_PUBLIC_TERM_MIN_OCCURRENCES', 3),

    /*
    |--------------------------------------------------------------------------
    | Query-log retention (M40-SEC-001)
    |--------------------------------------------------------------------------
    | `search_query_log` stores the VERBATIM text somebody typed, next to their
    | `user_id`. Before M40 it was written on every search and never removed, so
    | the platform accumulated an attributable record of what every user had
    | looked for, for as long as the database lived. M39 limited what is
    | PUBLISHED from that table; it did nothing about what is STORED.
    |
    | The declared policy lives in `RetentionRegistry::platformDefaults()` under
    | `search.query_log`; this is the period it reads.
    |
    | Why 90 days. The analytics that consume this table use much shorter
    | windows — trending defaults to 7 days (`trending_days`) and the admin
    | dashboards to 30 (`SearchAdminController::days()`). 90 leaves room for
    | quarter-over-quarter comparison with a wide margin while being nowhere
    | near indefinite. The admin dashboard accepts a `days` parameter up to 365;
    | beyond the retention window it will simply have less to report, which is
    | the intended trade and is documented rather than hidden.
    |
    | Nothing is deleted automatically. `search:purge-query-log` performs the
    | removal and its scheduled task ships DISABLED — see SearchServiceProvider.
    */
    'query_log_retention_days' => (int) env('SEARCH_QUERY_LOG_RETENTION_DAYS', 90),

    /*
    | Rows deleted per statement. Bounded so a first purge over a large backlog
    | is a series of small, interruptible deletes rather than one long
    | lock-holding transaction.
    */
    'query_log_purge_chunk' => (int) env('SEARCH_QUERY_LOG_PURGE_CHUNK', 1000),

    /*
    |--------------------------------------------------------------------------
    | Reindex map: domain event name => [document type, source provider key].
    | The event carries only an id; the index manager asks the named source
    | provider to hydrate the document from the owning context's table (a
    | read-only soft reference — never a write, never a cross-context join).
    |--------------------------------------------------------------------------
    */
    'index_events' => [
        'catalog.food_published' => ['type' => 'food', 'id_field' => 'foodId'],
        'catalog.recipe_published' => ['type' => 'recipe', 'id_field' => 'recipeId'],
        'commerce.product_published' => ['type' => 'product', 'id_field' => 'productId'],
        'marketplace.vendor_verified' => ['type' => 'vendor', 'id_field' => 'vendorId'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonym groups. Any term in a group expands the query to the whole group,
    | so "jollof" also matches "party rice", etc. Multi-language aliases (a
    | local name → the canonical term) live here too.
    |--------------------------------------------------------------------------
    */
    'synonyms' => [
        ['jollof', 'party rice'],
        ['swallow', 'fufu', 'eba', 'amala', 'pounded yam'],
        ['pepper soup', 'peppersoup'],
        ['beans', 'ewa', 'moimoi', 'moi moi'],
        ['pap', 'akamu', 'ogi'],
        ['groundnut', 'peanut'],
    ],
];
