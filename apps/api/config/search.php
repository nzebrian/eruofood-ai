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

    // Result cache TTL (seconds). 0 disables caching.
    'cache_ttl' => (int) env('SEARCH_CACHE_TTL', 120),

    // Default and maximum page sizes.
    'per_page' => (int) env('SEARCH_PER_PAGE', 20),
    'max_per_page' => (int) env('SEARCH_MAX_PER_PAGE', 100),

    // How many lexical candidates to pull before the vector re-rank.
    'candidate_pool' => (int) env('SEARCH_CANDIDATE_POOL', 200),

    // Blend of lexical vs. semantic score in the final ranking (0..1). Higher
    // weights lexical/full-text relevance; the remainder weights vector cosine.
    'lexical_weight' => (float) env('SEARCH_LEXICAL_WEIGHT', 0.6),

    // Whether to route the query string through the AI understanding adapter
    // (intent/expansion). Off by default so the default path stays offline.
    'ai_understanding' => (bool) env('SEARCH_AI_UNDERSTANDING', false),

    // Autocomplete suggestion count and trending window (days).
    'suggestion_limit' => (int) env('SEARCH_SUGGESTION_LIMIT', 8),
    'trending_days' => (int) env('SEARCH_TRENDING_DAYS', 7),
    'recent_limit' => (int) env('SEARCH_RECENT_LIMIT', 10),

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
