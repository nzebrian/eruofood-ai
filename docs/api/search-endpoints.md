# Search, Discovery & Recommendation — API Endpoints

Base URL: `/api/v1`. All paths are under **`/search`**. Discovery is **public**;
personalisation (recent, personalised recommendations), saved searches and admin
analytics require a bearer token. Admin user search and the analytics/reindex
endpoints additionally require the `admin` role. **No business module exposes its
own search** — every module publishes domain events, Search indexes the affected
documents, and all discovery flows through here. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Search

| Method & Path | Auth | Purpose |
|---|---|---|
| `GET /search` | public | Global or typed search with filters, sorting and facets. |
| `GET /search/autocomplete` | public | Type-ahead completions for a prefix (`q`, `type`). |
| `GET /search/trending` | public | Trending search terms. |
| `GET /search/click` → `POST /search/click` | public | Record a result click (`query_id`, `document_id`, `position`, `from_recommendation`) for CTR. |
| `GET /search/recent` | user | The caller's recent searches. |
| `GET /search/users` | admin | Admin user search (the pipeline gates the `user` scope). |

**Query parameters** (`GET /search`): `q`, `type`
(`global`\|`recipe`\|`food`\|`ingredient`\|`restaurant`\|`vendor`\|`product`\|`category`),
`sort` (`relevance`\|`popularity`\|`rating`\|`newest`\|`price`\|`prep_time`\|`distance`),
`page`, `per_page`; filters `region`, `cuisine`, `category`, `state`,
`ingredients` (csv), `dietary` (csv), `exclude_allergens` (csv), `max_calories`,
`min_price`, `max_price`, `min_rating`, `max_cooking_time`, `difficulty`; and
`lat`+`lng` for distance sorting.

Each result **hit** carries the document, the blended `score` and its
`lexical_score`/`semantic_score` components, an optional `distance_km`, and a
`highlight`. The response also returns `facets` (counts by type/region/cuisine)
for the filter sidebar and a `query_id` to attribute clicks.

## Recommendations

| Method & Path | Auth | Purpose |
|---|---|---|
| `GET /search/recommendations` | public | `kind` = `related`\|`similar`\|`restaurant`\|`seasonal`\|`trending`\|`frequently_viewed_together`; `type`, `anchor_id`, `limit`. Content-based kinds use `anchor_id`. |
| `GET /search/recommendations/personalised` | user | Recommendations from the user's recent activity (falls back to popularity on a cold start). |

## Saved searches

| Method & Path | Auth | Purpose |
|---|---|---|
| `GET /search/saved` | user | List the user's saved searches. |
| `POST /search/saved` | user | Save the current query (`name` + the `q`/`type`/`sort`/filter params). |
| `DELETE /search/saved/{id}` | user | Delete a saved search. |
| `POST /search/saved/{id}/run` | user | Re-run a saved search and return ranked results. |

## Search analytics (admin)

| Method & Path | Purpose |
|---|---|
| `GET /search/admin/metrics?days=` | Headline KPIs: total searches, unique terms, zero-result rate, CTR, avg results, recommendation CTR. |
| `GET /search/admin/popular?days=` | Most popular searches (terms with matches). |
| `GET /search/admin/failed?days=` | Failed (zero-result) searches — the content gaps. |
| `POST /search/admin/reindex` | Rebuild the index from source contexts (optional `type`). |

## Ranking & indexing (how it works)

- **Indexing** is event-driven: a `catalog.food_published` /
  `catalog.recipe_published` / `commerce.product_published` /
  `marketplace.vendor_verified` event triggers a read-only re-index of that item.
- **Ranking** blends a lexical score (full-text / term-overlap) with a semantic
  score (embedding cosine) via a configurable `lexical_weight`, plus a small
  popularity tie-breaker; non-relevance sorts order purely by the chosen facet.
- **Semantic search** stores an embedding per document; on Postgres it is mirrored
  to a native pgvector column (ivfflat) for accelerated recall, with an identical
  PHP cosine re-rank as the portable fallback.
