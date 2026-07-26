<?php

declare(strict_types=1);

use EruoFood\Nutrition\Interface\Http\Controller\Admin\NutritionItemAdminController;
use EruoFood\Nutrition\Interface\Http\Controller\DiaryController;
use EruoFood\Nutrition\Interface\Http\Controller\HealthProfileController;
use EruoFood\Nutrition\Interface\Http\Controller\MealAnalysisController;
use EruoFood\Nutrition\Interface\Http\Controller\MealPlanController;
use EruoFood\Nutrition\Interface\Http\Controller\NutritionCalculatorController;
use EruoFood\Nutrition\Interface\Http\Controller\NutritionItemController;
use EruoFood\Nutrition\Interface\Http\Controller\ProgressController;
use EruoFood\Nutrition\Interface\Http\Controller\RecommendationController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Nutrition routes (mounted under /api/v1 by the module provider)
|------------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // ---- Public nutrition database (read) ----
    Route::get('nutrition/items', [NutritionItemController::class, 'index']);
    Route::get('nutrition/items/{id}', [NutritionItemController::class, 'show']);

    // Ad-hoc calculators (no profile needed).
    Route::post('nutrition/calculate', [NutritionCalculatorController::class, 'calculate']);
    Route::post('nutrition/analyse', [MealAnalysisController::class, 'analyse']);

    // ---- Authenticated ----
    Route::middleware('auth.jwt')->prefix('nutrition')->group(function (): void {
        // Health profile
        Route::get('profile', [HealthProfileController::class, 'show']);
        Route::put('profile', [HealthProfileController::class, 'update']);

        // Assessment from the saved profile
        Route::get('assessment', [NutritionCalculatorController::class, 'assess']);

        // Daily nutrient tracking (diary)
        Route::get('diary', [DiaryController::class, 'day']);
        Route::post('diary', [DiaryController::class, 'store']);
        Route::delete('diary/{id}', [DiaryController::class, 'destroy']);

        // Meal plans + shopping lists
        Route::get('meal-plans', [MealPlanController::class, 'index']);
        Route::post('meal-plans', [MealPlanController::class, 'store']);
        Route::get('meal-plans/{id}', [MealPlanController::class, 'show']);
        Route::post('meal-plans/{id}/adjust', [MealPlanController::class, 'adjust']);
        Route::get('meal-plans/{id}/shopping-list', [MealPlanController::class, 'shoppingList']);
        Route::delete('meal-plans/{id}', [MealPlanController::class, 'destroy']);

        // Progress tracking
        Route::get('progress', [ProgressController::class, 'index']);
        Route::post('progress', [ProgressController::class, 'store']);

        // AI personalisation (throttled — each call may hit a provider)
        Route::middleware('throttle:20,1')->group(function (): void {
            Route::get('recommendations/meals', [RecommendationController::class, 'meals']);
            Route::get('recommendations/suggestions', [RecommendationController::class, 'suggestions']);
            Route::get('recommendations/diet-improvement', [RecommendationController::class, 'dietImprovement']);
            Route::get('recommendations/weekly-insights', [RecommendationController::class, 'weeklyInsights']);
        });
    });

    // ---- Admin: nutrition database (RBAC) ----
    Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin/nutrition/items')->group(function (): void {
        Route::post('/', [NutritionItemAdminController::class, 'store']);
        Route::put('{id}', [NutritionItemAdminController::class, 'update']);
        Route::delete('{id}', [NutritionItemAdminController::class, 'destroy']);
    });
});
