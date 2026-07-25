# Catalog — API Endpoints (Foods & Recipes)

Base URL: `/api/v1`. Public read endpoints need no auth; write endpoints need a
bearer token; admin endpoints need the `admin` role. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Foods & taxonomy (public)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /categories` | List categories | All active food categories, ordered. |
| `GET /ingredients?q=` | Search ingredients | Paginated ingredient search by name. |
| `GET /foods` | Browse/search foods | Filters: `q`, `category_id`, `region`, `state`, `tag`, `sort` (`name`\|`recent`), `page`. Returns food summaries. |
| `GET /foods/{slug}` | Food detail | Full food incl. local names, states, nutrition, image URLs, video URL. |
| `GET /foods/{foodId}/recipes` | Recipes for a food | Paginated recipe summaries for one food. |

## Recipes (public read)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /recipes` | Browse/search recipes | Filters: `q`, `food_id`, `difficulty`, `tag`, `max_minutes`, `sort` (`recent`\|`rating`\|`quick`). |
| `GET /recipes/{slug}` | Recipe detail | Ingredients, ordered steps (with image URLs), tips, rating, version, `is_favourited` (when authenticated). |
| `GET /recipes/{id}/related` | Related recipes | The recipe's curated related list. |
| `GET /recipes/{id}/reviews` | List reviews | Paginated ratings & reviews. |
| `GET /recipes/{id}/versions` | Version history | Immutable snapshots, newest first. |

## Recipes & engagement (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `POST /recipes` | Create recipe | Author = current user. Validates the linked food exists. Starts at version 1, status `draft`. |
| `PUT /recipes/{id}` | Update recipe | Owner or admin only. Bumps the version and records a snapshot. |
| `DELETE /recipes/{id}` | Delete recipe | Owner or admin only (soft delete). |
| `POST /recipes/{id}/reviews` | Rate & review | 1–5 stars + optional comment; one per user (re-submitting updates it). Recomputes the recipe's average. |
| `GET /me/favourites` | List favourites | The user's favourite recipes. |
| `POST /me/favourites/{recipeId}` | Add favourite | Idempotent. |
| `DELETE /me/favourites/{recipeId}` | Remove favourite | — |

## Admin (role: admin, prefix `/admin`)

| Method & Path | Purpose |
|---|---|
| `POST /admin/foods` · `PUT /admin/foods/{id}` | Create / update a food |
| `POST /admin/foods/{id}/publish` · `/archive` | Change publication state |
| `DELETE /admin/foods/{id}` | Soft-delete a food |
| `POST /admin/foods/{id}/images` · `DELETE /admin/foods/{id}/images` | Add / remove an image |
| `PUT /admin/foods/{id}/video` | Set/clear the (architecture-ready) video URL |
| `GET/POST /admin/categories`, `PUT /admin/categories/{id}`, `PATCH …/active`, `DELETE …` | Category management |
| `POST /admin/ingredients`, `PUT /admin/ingredients/{id}`, `DELETE …` | Ingredient management |
| `POST /admin/recipes/{id}/publish` · `/archive` · `PUT …/related` | Recipe moderation & related links |

## Error codes (catalog-specific)

| Code | HTTP | Meaning |
|---|---|---|
| `CATALOG_RESOURCE_NOT_FOUND` | 404 | Food/recipe/category/ingredient not found. |
| `DUPLICATE_SLUG` | 409 | Slug already in use (auto-uniquified on create where possible). |
| `ALREADY_REVIEWED` | 409 | (Reserved) duplicate review guard. |
| `NOT_AUTHORIZED` | 403 | Editing a recipe you don't own (and aren't admin). |
| `INVALID_ARGUMENT` | 422 | A value object rejected input (e.g. negative nutrition). |
