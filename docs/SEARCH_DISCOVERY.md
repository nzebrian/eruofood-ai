# Search & Discovery — operational contract (M38)

What the search subsystem guarantees, what it does not, and how to tell which.

Every section below exists because the opposite was true before M38 and nothing
reported it. The Phase A audit found seven defects that had been shipping for
months; each had the same shape — a silent one.

---

## 1. Consistency model

**Eventual, bounded by queue latency.**

A business context publishes a domain event (`catalog.food_published`, …).
Search's `EventIndexTranslator` enqueues a `ReindexDocumentJob` and returns. A
worker hydrates the document from the owning context, embeds it, upserts it and
invalidates the search cache.

Before M38 all of that happened **inline on the publishing HTTP request**. The
consistency was immediate and the cost was that a slow or failing index made
publishing slow or failing.

| | |
|---|---|
| Visibility delay | queue latency + `search.cache_ttl` (default 120s) |
| Ordering | not guaranteed across documents; per document the last write wins |
| Convergence | guaranteed — the document id is deterministic (`<type>:<sourceId>`) and the write is an upsert |

To restore synchronous behaviour for debugging or rollback, set
`SEARCH_ASYNC_INDEXING=false`. This is **not** a way to make tests pass:
`SearchAsyncIndexingTest` asserts the shipped default is asynchronous.

## 2. Queue behaviour and retry policy

| Setting | Env | Default |
|---|---|---|
| Queue | `SEARCH_QUEUE` | `search` |
| Attempts | `SEARCH_INDEX_JOB_TRIES` | 5 |
| Timeout | `SEARCH_INDEX_JOB_TIMEOUT` | 120s |
| Backoff | `search.index_job_backoff` | 10s, 30s, 120s, 300s |

`ReindexDocumentJob` is `ShouldBeUnique` on `type:sourceId`, which collapses a
burst of events for one document into a single queued job. **That is an
optimisation, not the correctness argument** — indexing is idempotent by
construction, so a lost unique lock and a duplicate delivery are both safe.

Exhausted retries land in `failed_jobs` and emit `SEARCH_INDEX_JOB_EXHAUSTED`.

## 3. Cache isolation and invalidation

Search **never** clears the shared application cache. Before M38
`LaravelSearchCache::flush()` called `$this->cache->clear()` while bound to the
default store, so one reindex evicted rate-limit counters, config and route
caches — and `reindexAll()` did it once per document.

Invalidation now bumps a Search-owned version counter:

```
eruofood:search:v7:<hash>      →      eruofood:search:v8:<hash>
```

Old entries become unreachable and expire on their own TTL. Nothing outside the
namespace is touched, and the operation is one `INCR` instead of a `FLUSH`, so a
full backfill invalidates **once at the end** rather than N times.

| Setting | Env | Default |
|---|---|---|
| TTL | `SEARCH_CACHE_TTL` | 120s (`0` disables) |
| Store | `SEARCH_CACHE_STORE` | default store |
| Namespace | `SEARCH_CACHE_PREFIX` | `eruofood:search` |

Tags are deliberately unused: they are unavailable on the `file` and `database`
stores this repository supports, and a strategy that degrades silently on some
stores is the class of problem M38 exists to remove.

## 4. Pagination and totals

`total` is a real `COUNT(*)` over the matching set. It was previously the size
of a truncated 200-row candidate pool, so any query matching more than 200
documents reported a false total **and every page past offset 200 came back
empty while still advertising more results**.

Two paths:

| Sort | Pagination | Depth |
|---|---|---|
| `popularity`, `rating`, `newest`, `price`, `prep_time` | SQL `ORDER BY … LIMIT/OFFSET` with a stable `id` tiebreak | exact at any depth |
| `relevance`, `distance` | blended in PHP over a materialised window | bounded by `SEARCH_MAX_RESULT_WINDOW` (default 1000) |

Asking for a page past the window raises `SEARCH_PAGINATION_TOO_DEEP` — an
explicit refusal naming the alternative, never a silent empty page.

`total_is_exact` is `false` only when a filter cannot be fully expressed in SQL
(today: `state`, which lives in a JSON array), in which case the count is an
upper bound and the response says so.

## 5. Vector capability — states, not assumptions

`GET /api/v1/search/admin/capability` reports what the database can actually do,
probed from `pg_extension` and `pg_indexes`:

| State | Meaning |
|---|---|
| `available` | verified present |
| `unavailable` | verified absent — the documented fallback applies |
| `probe_failed` | **could not be determined** — never treated as healthy |
| `disabled_by_config` | switched off deliberately; not a fault |

`native_vector_search` reads `active` only when the extension **and** its
ivfflat index are both present. Otherwise it reads `fallback`, which is the
honest name for the portable PHP cosine path.

> **Embeddings are not model-backed.** `HashingEmbeddingGenerator` feature-hashes
> tokens and bigrams into 64 dimensions. Texts sharing vocabulary score as
> similar; **synonyms and paraphrase do not work**. It is a lexical vector, not
> semantic AI. Binding a model-backed `EmbeddingGenerator` is a separate,
> unscheduled decision with cost and data-governance consequences.

**Provisioning.** Local and CI use `pgvector/pgvector:pg16`. **Production
provisioning is not assumed by this milestone** — where the extension is absent
the probe reports `unavailable`, the job reports `fallback`, and the fallback
path serves queries correctly but without native KNN.

## 6. Authorization

One gate, `SearchScopeGate`, used by **every** read path: search, autocomplete,
suggestions, recommendations, similar-document lookup and saved-search runs.

Before M38 the admin-only rule lived only in `SearchService::search()`.
`/autocomplete`, `/suggestions` and `/recommendations` are **public** routes that
accept `?type=user` and had no check at all. Nothing leaked only because no
`UserSourceProvider` exists — "that type is never indexed" is not a control.

Authorization runs **before** the index is queried and before the result cache is
read or written, so an unauthorised scope cannot be served from a warm entry.

## 7. Failure signals

Stable codes, matched by log queries and alert rules. Renaming one is an
operational change.

| Code | Level | Meaning |
|---|---|---|
| `SEARCH_INDEX_UNKNOWN_TYPE` | error | event names a type with no provider — the event map and provider list have drifted |
| `SEARCH_INDEX_SOURCE_MISSING` | info | source unpublished or deleted; the document is removed (expected) |
| `SEARCH_INDEX_PROVIDER_FAILED` | error | the owning context threw while hydrating |
| `SEARCH_INDEX_EMBEDDING_FAILED` | error | embedding generation threw |
| `SEARCH_INDEX_PERSIST_FAILED` | error | the index write threw |
| `SEARCH_INDEX_JOB_EXHAUSTED` | error | retries exhausted; see `failed_jobs` |
| `SEARCH_CAP_*` | info/warning | extension/index provisioning outcome at migration time |

**No document content is ever logged** — only `document_type` and `source_id`.
Indexed content is hydrated from other contexts and the log store is not cleared
to hold it.

## 8. Environment variables

```
SEARCH_ASYNC_INDEXING=true          # default; false restores synchronous indexing
SEARCH_QUEUE=search
SEARCH_INDEX_JOB_TRIES=5
SEARCH_INDEX_JOB_TIMEOUT=120
SEARCH_CACHE_TTL=120
SEARCH_CACHE_STORE=                 # blank = default store
SEARCH_CACHE_PREFIX=eruofood:search
SEARCH_MAX_RESULT_WINDOW=1000
SEARCH_CANDIDATE_POOL=200
SEARCH_VECTOR_ENABLED=true          # intent; the probe reports reality
SEARCH_FTS_ENABLED=true             # pg_trgm intent
SEARCH_USE_PGVECTOR=true
SEARCH_EMBEDDING_DIMS=64
POSTGRES_IMAGE=pgvector/pgvector:pg16
```

## 9. Known limitations

- **No PostgreSQL full-text search.** Matching is `LIKE '%term%'` accelerated by
  a pg_trgm GIN index. No stemming, no `ts_rank`. Recorded, not fixed here.
- **Relevance does not select the candidate pool without pgvector.** With native
  KNN unavailable the window is ordered by popularity, so a highly relevant but
  unpopular document outside the window is not ranked.
- **Embeddings are lexical, not semantic** (§5).
- **Geo distance is computed in PHP** with no spatial index.
- `state` filtering is refined in PHP, so totals for it are upper bounds.
