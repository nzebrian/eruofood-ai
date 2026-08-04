<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Provider;

use EruoFood\Catalog\Application\Port\ImageStorage;
use EruoFood\Catalog\Domain\Category\CategoryRepository;
use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Ingredient\IngredientRepository;
use EruoFood\Catalog\Domain\Recipe\FavouriteRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeReviewRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeVersionRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentCategoryRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentFavouriteRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentFoodRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentIngredientRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentRecipeRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentRecipeReviewRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\EloquentRecipeVersionRepository;
use EruoFood\Catalog\Infrastructure\Storage\S3ImageStorage;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Composition root for the Catalog (food database & recipes) module. */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(\Illuminate\Contracts\Config\Repository::class);

        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
        $this->app->bind(IngredientRepository::class, EloquentIngredientRepository::class);
        $this->app->bind(RecipeReviewRepository::class, EloquentRecipeReviewRepository::class);
        $this->app->bind(RecipeVersionRepository::class, EloquentRecipeVersionRepository::class);

        $this->app->bind(FoodRepository::class, fn (Application $app): EloquentFoodRepository
            => new EloquentFoodRepository($app->make(EventBus::class)));

        $this->app->bind(RecipeRepository::class, fn (Application $app): EloquentRecipeRepository
            => new EloquentRecipeRepository($app->make(EventBus::class)));

        $this->app->bind(FavouriteRepository::class, fn (Application $app): EloquentFavouriteRepository
            => new EloquentFavouriteRepository($app->make(RecipeRepository::class)));

        $this->app->bind(ImageStorage::class, fn (Application $app): S3ImageStorage
            => new S3ImageStorage($app->make('filesystem')->disk((string) $config->get('catalog.media_disk'))));
    }

    public function boot(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
    }
}
