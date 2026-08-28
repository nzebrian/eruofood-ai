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

**The boundary rule.** The requested page must fit **entirely** inside the
window:

```
offset + per_page <= SEARCH_MAX_RESULT_WINDOW
```

So with the default 1000-row window and 20 per page, offset 980 is the last
accepted page (980 + 20 = 1000) and offset 1000 is refused. A page that merely
*starts* inside the window is not enough. The first implementation tested only
`offset >= window`, which accepted a straddling page, clamped the window and
returned a short page — offset 995 with `per_page=20` answered with 5 hits while
`total` reported the full match count. Nothing in that response distinguishes a
truncated page from a genuinely final one, which is the original defect one page
further in.

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
read or written, so an unauthorised scope cannot be served from a warm entry. The
result cache key includes the scope, so a `global` request can never collect an
entry an administrator populated on the `user` scope.

### 6.1 The gate is necessary but not sufficient

`SearchScopeGate` authorises a scope **name**. It does not by itself constrain
what a query reads, and the first M38 implementation stopped there. It refused
an explicit `?type=user` while `EloquentSearchIndexRepository::suggest()` and
`::popular()` still treated `SearchType::Global` as *"apply no type filter"* —
so the **default** unauthenticated request leaked admin-only documents:

```
GET /api/v1/search/autocomplete?q=ada             -> 200 "Adaeze Private Person"
GET /api/v1/search/suggestions?q=ada              -> 200 "Adaeze Private Person"
GET /api/v1/search/recommendations?type=global    -> 200 the whole user document
```

Two rules now hold jointly:

1. **`SearchType::documentTypes()` is the only definition of a scope**, and the
   `Global` fan-out is *derived* from `SearchType::cases()` minus the admin-only
   cases — so a new admin-only type is excluded automatically rather than by
   someone remembering to edit a list.
2. **Every repository read constrains on it.** `scopeTypes()` is the single
   private helper in the index adapter, it is applied unconditionally, and there
   is no branch that leaves the type filter off. A null scope means the public
   `Global` fan-out, never "unfiltered".

Services pass the gate's **return value** (the resolved scope) to the index. The
first implementation discarded it and passed the caller's raw type on, which is
how a request authorised as "public" was executed as "unfiltered".

### 6.1a Public analytics are a separate boundary (M39-SEC-001)

`/search/trending` and `/search/suggestions` are public and serve **terms other
people typed**. Before M39 they consumed `SearchAnalyticsRepository::trending()`
and `::popular()` directly — the *administrative* reads, which aggregate every
row in `search_query_log` regardless of the scope it was recorded against and
apply no occurrence threshold. An anonymous caller therefore received:

- terms an administrator typed against the admin-only `user` scope;
- any authenticated user's terms, on any scope;
- terms searched **once, by one person**.

Documents never leaked — §6.1 held throughout — but query strings did.

**Two separate read paths now exist, and they are not interchangeable:**

| Method | Audience | Scope | Threshold |
|---|---|---|---|
| `publicTerms($days, $limit, $minOccurrences)` | public routes | `SearchType::publicScopeValues()` | `search.public_term_min_occurrences` (default **3**) |
| `popular()` / `failed()` / `trending()` | admin routes only | every scope | none |

Administrative analytics are deliberately **not** narrowed. Operators need
cross-scope and zero-result visibility — that is the point of failed-search
analysis — and restricting them would break the dashboards without improving
anything, because those endpoints are already behind `admin` RBAC.

**Why `publicScopeValues()` and not `Global->documentTypeValues()`.**
`search_query_log.type` records the scope *named on the request*, and a default
public search names `global`, which is **not** one of `Global`'s document types.
Filtering on `documentTypeValues()` alone would drop the most common public
search and quietly empty out trending. `publicScopeValues()` is every case that
is not admin-only — the seven public document types **plus `global`** — derived
from `SearchType::cases()` so a future admin-only scope is excluded
automatically.

**What the threshold does and does not give you.** A term must have at least
three qualifying public-scope occurrences before it can appear publicly; below
that it is withheld entirely, enforced as a SQL `HAVING` so a suppressed term is
never read out of the database. This is **privacy suppression, not anonymity**:
a term repeated often enough by a single determined user still qualifies, and it
offers no protection against an attacker who can generate occurrences. Raw query
strings remain sensitive data.

> **Not solved here.** `search_query_log` has no retention or pruning policy, so
> query strings are kept indefinitely. That is tracked separately as
> **M39-SEC-003** and is *not* addressed by this work.

### 6.2 Public read-path inventory

| Route | Auth | Accepted `type` | Documents it may return | Result cache | Repository call |
|---|---|---|---|---|---|
| `GET /api/v1/search` | public | any; absent → `global` | `type->documentTypes()`; `user` only for an admin | yes — key includes scope | `search()` → `whereIn(type, scopeTypes)` |
| `GET /api/v1/search/autocomplete` | public | any; absent → `global` | same | no | `suggest()` → `whereIn(type, scopeTypes)` |
| `GET /api/v1/search/suggestions` | public | any; absent → `global` | same, plus popular past **query terms** (not documents) | no | `suggest()` + analytics |
| `GET /api/v1/search/trending` | public | n/a | past query terms only — no documents | no | analytics only |
| `GET /api/v1/search/recommendations` | public | any; absent → `food` | same | no | `popular()` / `similarTo()`, both scoped |
| `POST /api/v1/search/click` | public | n/a | writes an analytics row; returns 204 | no | analytics only |
| `GET /api/v1/search/recent` | JWT | n/a | that user's own past terms | no | analytics only |
| `GET /api/v1/search/recommendations/personalised` | JWT | any; absent → `food` | same as recommendations | no | scoped |
| `GET /api/v1/search/users` | JWT + `admin` | forced `user` | `user` | yes — key includes scope | `search()`, scoped |
| `GET /api/v1/search/saved`, `saved/{id}/run` | JWT | any | the owner's saved query, re-run through the same pipeline | via pipeline | `search()`, scoped |
| `GET /api/v1/search/admin/*` | JWT + `admin` | n/a | analytics + capability, no document bodies | no | analytics / probe |

No endpoint relies on controller or service validation alone: the query layer
enforces the allowed document types independently, so a new endpoint that forgets
the gate still cannot read an admin-only type through `Global`.

`modules/Search/tests/Feature/SearchAuthorizationTest.php` drives every public
row of this table with a `user` document seeded at the top of the index, and
asserts on the **response body** — never on the status code alone. A 200 is not
accepted as evidence of anything; the earlier version of that file asserted
`assertOk()` on the leaking request and passed.

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
SEARCH_CAPABILITY_TTL=30            # seconds a probed capability may be reused; 0 = always re-probe
SEARCH_PUBLIC_TERM_MIN_OCCURRENCES=3  # public trending/suggestions suppression threshold (§6.1a); 1 disables it
POSTGRES_IMAGE=pgvector/pgvector:pg16
```

**On `SEARCH_CAPABILITY_TTL`.** The index repository is a container singleton and
is held by further singletons. Under PHP-FPM — which is what this application
deploys on; there is no Octane, Swoole or RoadRunner in `composer.json` — the
container is rebuilt per request, so a capability memo really is request-scoped
on the web path. **A queue worker is not**: it is a long-lived process, so a
worker started before the acceleration migration provisioned `vector` would
otherwise cache "absent" and never write the `embedding_vec` column again for as
long as it ran. The memo is therefore bounded rather than permanent.

## 9. Known limitations

- **No PostgreSQL full-text search.** Matching is `LIKE '%term%'` accelerated by
  a pg_trgm GIN index. No stemming, no `ts_rank`. Recorded, not fixed here.
- **Relevance does not select the candidate pool without pgvector.** With native
  KNN unavailable the window is ordered by popularity, so a highly relevant but
  unpopular document outside the window is not ranked.
- **Embeddings are lexical, not semantic** (§5).
- **Geo distance is computed in PHP** with no spatial index.
- `state` filtering is refined in PHP, so totals for it are upper bounds.
- **Query-log retention is unbounded (M39-SEC-003).** `search_query_log` has no
  pruning job and no retention window, so raw query strings accumulate
  indefinitely. The public-analytics suppression in §6.1a limits what is
  *published*; it does nothing about what is *stored*. Open work item.
