<?php

declare(strict_types=1);

use EruoFood\Search\Interface\Http\Controller\RecommendationController;
use EruoFood\Search\Interface\Http\Controller\SavedSearchController;
use EruoFood\Search\Interface\Http\Controller\SearchAdminController;
use EruoFood\Search\Interface\Http\Controller\SearchController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Search routes — Search, Discovery & Recommendation Engine (mounted under
| /api/v1 by the module provider). Discovery is public; personalisation, saved
| searches and admin analytics require authentication. Everything lives under
| "search" so it never collides with other contexts. No business module exposes
| its own search — all discovery flows through here.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/search')->group(function (): void {
    // ---- Public discovery ----
    Route::get('/', [SearchController::class, 'search']);
    Route::get('autocomplete', [SearchController::class, 'autocomplete']);
    Route::get('suggestions', [SearchController::class, 'suggestions']);
    Route::get('trending', [SearchController::class, 'trending']);
    Route::post('click', [SearchController::class, 'click']);
    Route::get('recommendations', [RecommendationController::class, 'index']);
});

Route::prefix('v1/search')->middleware('auth.jwt')->group(function (): void {
    // ---- Personalisation ----
    Route::get('recent', [SearchController::class, 'recent']);
    Route::get('recommendations/personalised', [RecommendationController::class, 'personalised']);

    // ---- Admin user search (the pipeline gates the User scope) ----
    Route::get('users', [SearchController::class, 'users']);

    // ---- Saved searches ----
    Route::get('saved', [SavedSearchController::class, 'index']);
    Route::post('saved', [SavedSearchController::class, 'store']);
    Route::delete('saved/{id}', [SavedSearchController::class, 'destroy']);
    Route::post('saved/{id}/run', [SavedSearchController::class, 'run']);

    // ---- Search analytics + reindex (admin role) ----
    Route::get('admin/metrics', [SearchAdminController::class, 'metrics']);
    Route::get('admin/popular', [SearchAdminController::class, 'popular']);
    Route::get('admin/failed', [SearchAdminController::class, 'failed']);
    Route::post('admin/reindex', [SearchAdminController::class, 'reindex']);
});
