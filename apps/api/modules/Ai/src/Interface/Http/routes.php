<?php

declare(strict_types=1);

use EruoFood\Ai\Interface\Http\Controller\Admin\PromptAdminController;
use EruoFood\Ai\Interface\Http\Controller\AiAssistantController;
use EruoFood\Ai\Interface\Http\Controller\AiRecipeController;
use EruoFood\Ai\Interface\Http\Controller\AiUsageController;
use EruoFood\Ai\Interface\Http\Controller\ConversationController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| AI Engine routes (mounted under /api/v1 by the module provider)
|------------------------------------------------------------------------------
| Every generation endpoint is authenticated so calls can be rate-limited and
| cost-attributed per user. A per-route throttle sits in front of the shared
| AiRateLimiter as a cheap first line of defence.
*/

Route::prefix('v1')->group(function (): void {
    // ---- Authenticated AI features ----
    Route::middleware('auth.jwt')->prefix('ai')->group(function (): void {
        // Recipe authoring & content generation
        Route::middleware('throttle:30,1')->group(function (): void {
            Route::post('recipes/generate', [AiRecipeController::class, 'generate']);
            Route::post('recipes/improve', [AiRecipeController::class, 'improve']);
            Route::post('recipes/leftovers', [AiRecipeController::class, 'leftovers']);
            Route::post('recipes/summarize', [AiRecipeController::class, 'summarize']);
            Route::post('recipes/translate', [AiRecipeController::class, 'translate']);
            Route::post('foods/describe', [AiRecipeController::class, 'describeFood']);

            // Smart Cooking Assistant & helpers
            Route::post('assistant/chat', [AiAssistantController::class, 'chat']);
            Route::post('assistant/tips', [AiAssistantController::class, 'tips']);
            Route::post('assistant/substitute', [AiAssistantController::class, 'substitute']);
            Route::post('assistant/meals', [AiAssistantController::class, 'mealSuggestions']);
        });

        // Chat history
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/{id}', [ConversationController::class, 'show']);
        Route::patch('conversations/{id}', [ConversationController::class, 'rename']);
        Route::delete('conversations/{id}', [ConversationController::class, 'destroy']);

        // Usage & cost (AI settings screen)
        Route::get('usage', [AiUsageController::class, 'me']);
    });

    // ---- Admin: Prompt Management System (RBAC) ----
    Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin/ai/prompts')->group(function (): void {
        Route::get('/', [PromptAdminController::class, 'index']);
        Route::post('/', [PromptAdminController::class, 'store']);
        Route::post('{id}/activate', [PromptAdminController::class, 'activate']);
    });
});
