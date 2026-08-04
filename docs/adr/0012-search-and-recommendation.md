# ADR-0012: Search — an event-fed index with a portable full-text + pgvector ranking pipeline

- **Status:** Accepted
- **Date:** 2026-07-28
- **Deciders:** Engineering, Product, Data

## Context

Milestone 12 adds Search, Discovery & Recommendation: global and typed search
(recipes, foods, ingredients, restaurants, vendors, products, categories, admin
user search); full-text + semantic (pgvector) matching with fuzzy/typo/synonym
support; autocomplete, suggestions, trending, recent and saved searches; the
full filter and sort matrix; a recommendation engine (related/similar/
personalised/frequently-viewed-together/seasonal/trending); and search
analytics (popular, failed, CTR, recommendation performance). The hard
requirements: an **independent bounded context**, **no business module performs
direct database searching**, and **all search goes through the Search Domain**.

## Decision

- **A standalone `EruoFood\Search` context that owns its own index.** Search
  never queries another context's tables to answer a query. It maintains a
  denormalised `search_documents` index (title, description, keywords, filter
  facets, geo, and an embedding) and every query — global, typed, autocomplete,
  similarity — runs against that index through one port,
  `SearchIndexRepository`. No other module exposes or performs search.
- **Indexing is event-fed, hydrated by read-only source providers.** A config
  `index_events` map ties a published event name (`catalog.food_published`,
  `commerce.product_published`, `marketplace.vendor_verified`, …) to a document
  type. The `EventIndexTranslator` reacts by name (reading the source id from the
  event's public properties via reflection — never importing another context's
  event class), and the `SearchIndexManager` asks the matching
  `SourceDocumentProvider` to hydrate the document with a **read-only** lookup
  over the owning table (a soft reference, no join, no write). A source that has
  been unpublished yields `null`, and the index entry is removed. A
  `search:reindex` command backfills from the same providers.
- **A retrieve-then-rerank pipeline that is portable and pgvector-accelerated.**
  Recall is pushed to SQL: document type + scalar filters + a lexical prefilter.
  The bounded candidate pool is then re-ranked **in PHP** with identical
  semantics everywhere — full filter matching (`DocumentFacets::matches`),
  lexical scoring (term/title overlap), semantic scoring (embedding cosine mapped
  to [0,1]), blended by the `Ranker` (lexical weight + a logarithmic popularity
  tie-breaker), geo distance, sort and pagination. On Postgres the stored
  embedding is mirrored into a native `vector` column (ivfflat) and pg_trgm backs
  the lexical prefilter, so candidate selection is index-accelerated — but the
  **ranking maths never changes**, which is why the sqlite test suite is a
  faithful check of production behaviour.
- **A swappable embedder behind a port.** The default `EmbeddingGenerator` is a
  deterministic, dependency-free feature-hashing embedder (bag-of-words +
  bigrams, L2-normalised) — offline, reproducible, and good enough that
  vocabulary-sharing texts rank close. Because ranking depends only on cosine,
  binding a model-backed embedder (via the AI engine) upgrades quality with no
  pipeline change. Likewise `QueryUnderstanding` is a pass-through by default and
  an AI-backed adapter (the published `AiAdvisor` contract) when enabled.
- **Analytics as an append-only log with click attribution.** Every executed
  query is logged with its result count (so zero-result "failed" searches
  surface) and returns a `query_id`; result clicks post back that id, giving
  click-through and recommendation-CTR. Popular/trending/recent term lists and
  the admin KPIs read this log.

## Consequences

- **Positive:** search is fully decoupled — no module searches directly or knows
  Search exists; indexing is one-way and by event name; the same code path is
  correct on sqlite and fast on Postgres; semantic search works offline and
  upgrades to a real model by swapping one binding; new indexable types are a
  provider + a config line; the ranking algorithm is pure and unit-tested.
- **Negative / trade-offs:** the index is eventually consistent with its sources
  (an event drives the update); the portable re-rank bounds work to a candidate
  pool (`candidate_pool`), so extreme-recall queries rely on the SQL prefilter's
  quality — pg_trgm/pgvector close that gap on Postgres; the hashing embedder is
  a semantic approximation, not a trained model; "frequently viewed together"
  currently falls back to content similarity until co-view volume accrues.
- **Follow-ups:** a model-backed embedder + AI query understanding enabled in
  production; a queued/batch reindexer for large backfills; co-view behavioural
  recommendations from the click log; learning-to-rank weights tuned from CTR;
  and voice/image search adapters (the query pipeline already accepts an
  arbitrary term string + embedding, so both slot in behind the existing ports).

## Alternatives considered

- **Query each context's tables directly / a shared search across modules** —
  rejected outright: violates "no module searches directly" and couples search to
  every schema.
- **Adopt Elasticsearch/OpenSearch/Meilisearch now** — rejected for launch:
  another operational dependency, and the requirement names PostgreSQL + pgvector.
  The port-based design means an external engine can later back
  `SearchIndexRepository` without touching callers.
- **Full event-sourced index / offline ANN service** — heavier than needed; the
  event-fed document table plus pgvector gives most of the benefit and keeps the
  index self-contained and rebuildable from source.
