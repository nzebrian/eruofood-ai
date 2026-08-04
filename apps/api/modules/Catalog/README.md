# Catalog Module (`EruoFood\Catalog`)

The Nigerian Food Database & Recipe Management bounded context. Built with Clean
Architecture, DDD, the Repository Pattern, a Service Layer, and DI — the same
conventions as the Identity module.

## What it owns

- **Food database:** foods by region/state, categories, ingredients, nutrition,
  multiple local names + English names, images, video (architecture-ready),
  search & filtering.
- **Recipes:** CRUD, step-by-step instructions, prep/cook time, difficulty,
  serving size, ingredients with quantities, tips, tags, related recipes,
  favourites, ratings & reviews, and **versioning**.
- **Admin:** management of foods, categories, ingredients, images, and recipe
  moderation (publish/archive).

## Folder structure

```
modules/Catalog/src/
├── Domain/                     # Pure PHP — no framework
│   ├── Enum/                   # ContentStatus, FoodRegion, CategoryType, Difficulty, MeasurementUnit
│   ├── ValueObject/            # NutritionalInfo, Measurement, LocalName, Rating,
│   │                          #   RecipeIngredient, CookingStep
│   ├── Category/               # Category aggregate + repository port
│   ├── Ingredient/             # Ingredient aggregate + repository port
│   ├── Food/                   # Food aggregate root + repository + search criteria
│   ├── Recipe/                 # Recipe aggregate root, RecipeReview, and the
│   │                          #   Recipe/Review/Favourite/Version repository ports
│   ├── Event/                  # FoodPublished, RecipePublished
│   └── Exception/              # CatalogNotFound, DuplicateSlug, AlreadyReviewed, NotRecipeAuthor
├── Application/
│   ├── Service/                # SERVICE LAYER: Category, Ingredient, Food, Recipe,
│   │                          #   RecipeReview, Favourite, CatalogPresenter
│   ├── Input/                  # FoodInput, RecipeInput (validated command DTOs)
│   └── Port/                   # ImageStorage
├── Infrastructure/
│   ├── Persistence/Eloquent/   # Models + repositories (catalog_* tables)
│   ├── Persistence/Migration/  # 7 module-owned migrations
│   ├── Storage/                # S3ImageStorage
│   ├── Seeder/                 # NigerianFoodSeeder (starter data)
│   └── Provider/               # CatalogServiceProvider (composition root)
└── Interface/Http/
    ├── Controller/             # Public: Food, Category, Ingredient, Recipe,
    │                          #   RecipeReview, Favourite; Admin/: Food, Category,
    │                          #   Ingredient, Recipe admin controllers
    ├── Request/                # FoodRequest, RecipeRequest
    ├── Concerns/               # ResolvesAuthUser, RespondsWithData
    └── routes.php
```

## Database tables

| Table | Purpose |
|-------|---------|
| `catalog_categories` | Food categories (Soups, Swallows, Rice, …). Unique slug. |
| `catalog_ingredients` | Reusable ingredients with local names + per-100g nutrition. |
| `catalog_foods` | Foods: region, states (jsonb), local names (jsonb), nutrition, images (jsonb), video_url, tags, status. Soft-deleted. |
| `catalog_recipes` | Recipes: food_id + author_id (soft refs), times, difficulty, serving size, ingredients/steps/tips/tags/related (jsonb), status, **version**, denormalised rating_average/rating_count. Soft-deleted. |
| `catalog_recipe_reviews` | One rating+review per user per recipe (unique). |
| `catalog_recipe_favourites` | User ↔ recipe favourite links (unique). |
| `catalog_recipe_versions` | Immutable snapshot per recipe version (recipe versioning). |

All cross-context references (category_id, food_id, author_id) are **soft
references** — no cross-context foreign keys (MASTER_PLAN §5).

## Key design decisions

1. **Two aggregate roots (Food, Recipe)** plus Category/Ingredient aggregates,
   each with its own repository port. Value objects (NutritionalInfo,
   Measurement, LocalName, RecipeIngredient, CookingStep) make the model
   self-validating.
2. **Service Layer + ports.** Services orchestrate; `ImageStorage` is a port
   with an S3 adapter. Presentation goes through `CatalogPresenter`, which
   resolves media paths to URLs so controllers/resources never touch storage.
3. **Search & filtering in the repository.** `FoodSearchCriteria` /
   `RecipeSearchCriteria` express queries; the Eloquent repositories translate
   them (portable `LOWER() LIKE`, `whereJsonContains` for tags/states).
4. **Recipe versioning.** Editing a recipe bumps `version` and writes an
   immutable snapshot to `catalog_recipe_versions`, giving full history.
5. **Denormalised ratings.** Reviews update a cached `rating_average`/`rating_count`
   on the recipe so listing/sorting by rating is cheap.
6. **Authorization.** Recipe edits require the author or an admin (enforced in
   the service, surfaced as `NOT_AUTHORIZED`/403). Food/category/ingredient
   management is admin-only (route middleware `role:admin`).
7. **Video is architecture-ready:** foods carry a nullable `video_url`; richer
   video handling (transcoding, streaming) plugs in later without schema churn.
8. **Local disk in dev/test, S3 in prod** for media, via `catalog.media_disk`.

## Seeding

```bash
php artisan db:seed --class="EruoFood\Catalog\Infrastructure\Seeder\NigerianFoodSeeder"
```

Seeds categories, 8 real Nigerian foods across regions (with local names +
nutrition), and a Jollof Rice recipe.
