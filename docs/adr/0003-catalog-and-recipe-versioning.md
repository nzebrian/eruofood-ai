# ADR-0003: Catalog design — one context, JSON-embedded detail, recipe versioning

- **Status:** Accepted
- **Date:** 2026-07-25
- **Deciders:** Engineering, Product

## Context

Milestone 3 adds the Nigerian food database and recipe management. Foods carry
rich, list-shaped detail (local names, states, images, nutrition) and recipes
own ordered ingredients and steps, ratings, favourites, and must retain history.

## Decision

- **Single `Catalog` bounded context** holds Food, Recipe, Category, and
  Ingredient aggregates. They share a ubiquitous language and change together;
  ratings/favourites live here for this milestone and can later move to a
  dedicated Reviews context (contracts + events already isolate them).
- **Aggregate-owned detail embedded as JSON(B).** A food's local names/states/
  images and a recipe's ingredients/steps/tips/tags are value-object collections
  stored in `jsonb` columns rather than child tables. They are only ever read
  and written through their aggregate root, so separate tables would add joins
  without adding integrity we don't already enforce in the domain.
- **Recipe versioning via snapshots.** Editing a recipe bumps an integer
  `version` and appends an immutable row to `catalog_recipe_versions`. History
  is queryable without event sourcing the whole aggregate.
- **Denormalised rating summary** (`rating_average`, `rating_count`) is updated
  whenever a review is saved, keeping "sort by rating" and cards cheap.
- **Search/filter in the repository** using portable SQL (`LOWER() LIKE`,
  `whereJsonContains`) so it runs on SQLite (tests) and PostgreSQL (prod).

## Consequences

- **Positive:** simple schema, fewer joins, atomic aggregate writes, real
  version history, cheap rating reads, extractable seams.
- **Negative / trade-offs:** JSONB detail is not independently queryable at the
  row level (acceptable — we query foods/recipes, not individual steps). Full-
  text search is basic today; a dedicated search index (OpenSearch/pgvector) is
  the upgrade path in later phases (MASTER_PLAN §5.5, §8).

## Alternatives considered

- **Separate child tables** for images/ingredients/steps — rejected: more joins
  and migrations for data only ever accessed via the aggregate.
- **Event sourcing recipes** — rejected as overkill; snapshot-per-version gives
  the needed history far more simply.
- **Ratings/reviews as a separate context now** — deferred; not worth the
  cross-context plumbing at this stage.
