# Search Module (`EruoFood\Search`)

The **Search, Discovery & Recommendation Engine** bounded context — the one
place discovery happens on the platform: global and typed search, autocomplete,
suggestions, trending/recent/saved searches, the full filter and sort matrix,
the recommendation engine, and search analytics.

**No business module searches its own tables.** Modules publish domain events;
Search reacts by re-indexing the affected document (read via a soft, read-only
source lookup) into its own index, and every query — global, typed, autocomplete,
similarity — runs against that index. The only inbound coupling is a config map
keyed by the event's stable name; Search never imports another context's classes,
and no other context imports Search.

## What it owns

- **Index** (`SearchDocument`, `DocumentFacets`, `SearchIndexRepository`) — a
  denormalised, per-item projection with keywords, filter facets, geo and a
  semantic embedding; deterministic ids (`type:sourceId`) so re-indexing upserts.
- **Query pipeline** (`SearchService`, `QueryBuilder`, `Ranker`) — normalisation,
  synonym + optional AI expansion, a lexical-prefilter + filter push-down + PHP
  re-rank blending full-text and vector cosine, a read-through cache, and
  analytics recording. Full-text + **pgvector** semantic search, fuzzy/typo
  tolerance, synonyms, multi-language, and voice/image-search-ready ports.
- **Recommendation engine** (`RecommendationService`) — related/similar
  (vector), personalised (recent activity), restaurant/seasonal/trending
  (popularity), frequently-viewed-together.
- **Autocomplete & discovery** (`AutocompleteService`) — prefix completions,
  blended suggestions, trending, recent (per user), and saved searches.
- **Analytics** (`SearchAnalyticsRepository`) — an append-only query log with
  click attribution: popular, failed (zero-result), CTR, and recommendation
  performance.

## Folder structure

```
modules/Search/src/
├── Domain/                   # Pure PHP — no framework
│   ├── Enum/                 # SearchType, SortOption, RecommendationType
│   ├── ValueObject/          # SearchQuery, SearchFilters, GeoPoint, Embedding
│   ├── Document/             # SearchDocument, DocumentFacets, Ranker, SearchHit/Results + index port
│   ├── Recommendation/ · SavedSearch/ · Analytics/   # aggregates, read models + ports
│   └── Exception/            # not-found / invalid-query / conflict / not-authorized
├── Application/              # Use cases + ports
│   ├── Port/                 # EmbeddingGenerator, QueryUnderstanding, SourceDocumentProvider, SearchCache
│   ├── DTO/                  # ExecutedSearch
│   └── Service/              # Search, QueryBuilder, SearchIndexManager, Autocomplete,
│                             #   Recommendation, SearchAnalytics, EventIndexTranslator, Presenter
├── Infrastructure/           # Adapters
│   ├── Persistence/          # Eloquent models + repositories (pgvector-guarded), 4 migrations
│   ├── Source/               # Food/Recipe/Product/Vendor read-only source providers
│   ├── Embedding/            # HashingEmbeddingGenerator (offline, deterministic)
│   ├── Understanding/        # Passthrough + AI (via AiAdvisor contract)
│   ├── Cache/                # LaravelSearchCache
│   ├── Event/                # DomainEventSubscriber (bus → index translator)
│   ├── Console/              # search:reindex
│   └── Provider/             # SearchServiceProvider (composition root)
└── Interface/                # HTTP (controllers, param concerns, routes)
```

## Why it's decoupled

- **Indexing in, by event name.** `EventIndexTranslator` subscribes to the
  configured `index_events` and reads the source id from each event's public
  properties via reflection — no imported event classes.
- **Reads via source ports.** `SourceDocumentProvider` adapters read
  `catalog_foods` / `catalog_recipes` / `commerce_products` /
  `marketplace_vendors` read-only to hydrate documents — a soft reference, never
  a join or a write.
- **One query port.** All discovery goes through `SearchIndexRepository`; the
  adapter chooses native full-text + pgvector on Postgres, or a portable
  lexical-prefilter + PHP re-rank elsewhere, with identical ranking semantics.

See [`docs/api/search-endpoints.md`](../../../../docs/api/search-endpoints.md)
for the endpoints and [ADR-0012](../../../../docs/adr/0012-search-and-recommendation.md)
for the ranking algorithm, index design and architectural decisions.
