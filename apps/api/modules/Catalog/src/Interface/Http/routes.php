<?php

declare(strict_types=1);

use EruoFood\Catalog\Interface\Http\Controller\Admin\CategoryAdminController;
use EruoFood\Catalog\Interface\Http\Controller\Admin\FoodAdminController;
use EruoFood\Catalog\Interface\Http\Controller\Admin\IngredientAdminController;
use EruoFood\Catalog\Interface\Http\Controller\Admin\RecipeAdminController;
use EruoFood\Catalog\Interface\Http\Controller\CategoryController;
use EruoFood\Catalog\Interface\Http\Controller\FavouriteController;
use EruoFood\Catalog\Interface\Http\Controller\FoodController;
use EruoFood\Catalog\Interface\Http\Controller\IngredientController;
use EruoFood\Catalog\Interface\Http\Controller\RecipeController;
use EruoFood\Catalog\Interface\Http\Controller\RecipeReviewController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Catalog routes (mounted under /api/v1 by the module provider)
|------------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // ---- Public catalogue (read) ----
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('ingredients', [IngredientController::class, 'index']);

    Route::get('foods', [FoodController::class, 'index']);
    Route::get('foods/{slug}', [FoodController::class, 'show']);
    Route::get('foods/{foodId}/recipes', [RecipeController::class, 'byFood']);

    Route::get('recipes', [RecipeController::class, 'index']);
    Route::get('recipes/{slug}', [RecipeController::class, 'show']);
    Route::get('recipes/{id}/related', [RecipeController::class, 'related']);
    Route::get('recipes/{id}/reviews', [RecipeController::class, 'reviews']);
    Route::get('recipes/{id}/versions', [RecipeController::class, 'versions']);

    // ---- Authenticated ----
    Route::middleware('auth.jwt')->group(function (): void {
        // User-authored recipes (owner or admin enforced in the service).
        Route::post('recipes', [RecipeController::class, 'store']);
        Route::put('recipes/{id}', [RecipeController::class, 'update']);
        Route::delete('recipes/{id}', [RecipeController::class, 'destroy']);

        // Ratings & reviews
        Route::post('recipes/{recipeId}/reviews', [RecipeReviewController::class, 'store'])->middleware('throttle:20,1');

        // Favourites
        Route::get('me/favourites', [FavouriteController::class, 'index']);
        Route::post('me/favourites/{recipeId}', [FavouriteController::class, 'store']);
        Route::delete('me/favourites/{recipeId}', [FavouriteController::class, 'destroy']);
    });

    // ---- Admin (RBAC) ----
    Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin')->group(function (): void {
        Route::get('categories', [CategoryAdminController::class, 'index']);
        Route::post('categories', [CategoryAdminController::class, 'store']);
        Route::put('categories/{id}', [CategoryAdminController::class, 'update']);
        Route::patch('categories/{id}/active', [CategoryAdminController::class, 'setActive']);
        Route::delete('categories/{id}', [CategoryAdminController::class, 'destroy']);

        Route::post('ingredients', [IngredientAdminController::class, 'store']);
        Route::put('ingredients/{id}', [IngredientAdminController::class, 'update']);
        Route::delete('ingredients/{id}', [IngredientAdminController::class, 'destroy']);

        Route::post('foods', [FoodAdminController::class, 'store']);
        Route::put('foods/{id}', [FoodAdminController::class, 'update']);
        Route::post('foods/{id}/publish', [FoodAdminController::class, 'publish']);
        Route::post('foods/{id}/archive', [FoodAdminController::class, 'archive']);
        Route::delete('foods/{id}', [FoodAdminController::class, 'destroy']);
        Route::post('foods/{id}/images', [FoodAdminController::class, 'uploadImage']);
        Route::delete('foods/{id}/images', [FoodAdminController::class, 'deleteImage']);
        Route::put('foods/{id}/video', [FoodAdminController::class, 'setVideo']);

        Route::post('recipes/{id}/publish', [RecipeAdminController::class, 'publish']);
        Route::post('recipes/{id}/archive', [RecipeAdminController::class, 'archive']);
        Route::put('recipes/{id}/related', [RecipeAdminController::class, 'setRelated']);
    });
});
