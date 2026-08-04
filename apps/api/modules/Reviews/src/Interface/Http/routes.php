<?php

declare(strict_types=1);

use EruoFood\Reviews\Interface\Http\Controller\ModerationController;
use EruoFood\Reviews\Interface\Http\Controller\ReviewAdminController;
use EruoFood\Reviews\Interface\Http\Controller\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Reviews routes — Reviews & Ratings (mounted under /api/v1 by the module
| provider). Browsing a subject's published reviews and its authoritative rating
| summary is public; submitting/editing/voting/responding needs authentication;
| the moderation queue and analytics require a moderator/admin role (enforced in
| the controllers). Everything lives under "reviews" so it never collides with
| other contexts. No business module stores or aggregates its own reviews — all
| reviews flow through here, and ratings flow out via the rating-summary event.
|------------------------------------------------------------------------------
*/

$subjectTypes = 'product|food|recipe|vendor|restaurant|rider';
$uuid = '[0-9a-fA-F-]{36}';

// ---- Public read surface ----
Route::prefix('v1/reviews')->group(function () use ($subjectTypes, $uuid): void {
    Route::get('{subjectType}/{subjectId}', [ReviewController::class, 'index'])->where('subjectType', $subjectTypes);
    Route::get('{subjectType}/{subjectId}/summary', [ReviewController::class, 'summary'])->where('subjectType', $subjectTypes);
    Route::get('{id}', [ReviewController::class, 'show'])->where('id', $uuid);
});

Route::prefix('v1/reviews')->middleware('auth.jwt')->group(function () use ($uuid, $subjectTypes): void {
    // ---- Customer surface ----
    Route::get('me', [ReviewController::class, 'mine']);
    Route::post('/', [ReviewController::class, 'store']);
    Route::put('{id}', [ReviewController::class, 'update'])->where('id', $uuid);
    Route::post('{id}/vote', [ReviewController::class, 'vote'])->where('id', $uuid);
    Route::post('{id}/response', [ReviewController::class, 'respond'])->where('id', $uuid);

    // ---- Moderation workspace ----
    Route::get('moderation/queue', [ModerationController::class, 'queue']);
    Route::post('moderation/{id}/approve', [ModerationController::class, 'approve'])->where('id', $uuid);
    Route::post('moderation/{id}/reject', [ModerationController::class, 'reject'])->where('id', $uuid);
    Route::post('moderation/{id}/remove', [ModerationController::class, 'remove'])->where('id', $uuid);

    // ---- Admin analytics ----
    Route::get('admin/analytics', [ReviewAdminController::class, 'overview']);
    Route::get('admin/top-rated/{subjectType}', [ReviewAdminController::class, 'topRated'])->where('subjectType', $subjectTypes);
});
