<?php

declare(strict_types=1);

use EruoFood\Identity\Interface\Http\Controller\Admin\UserAdminController;
use EruoFood\Identity\Interface\Http\Controller\AuthController;
use EruoFood\Identity\Interface\Http\Controller\EmailVerificationController;
use EruoFood\Identity\Interface\Http\Controller\PasswordResetController;
use EruoFood\Identity\Interface\Http\Controller\ProfileController;
use EruoFood\Identity\Interface\Http\Controller\SessionController;
use EruoFood\Identity\Interface\Http\Controller\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Identity & Access routes (mounted under /api/v1 by the module provider)
|------------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // ---- Public authentication ----
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('login/two-factor', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:10,1');
        Route::post('login/social', [AuthController::class, 'social'])->middleware('throttle:10,1');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');
        Route::post('logout', [AuthController::class, 'logout']);

        Route::post('email/verify', [EmailVerificationController::class, 'verify']);
        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:6,1');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');
    });

    // ---- Authenticated ----
    Route::middleware('auth.jwt')->group(function (): void {
        Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1');

        // Current user's account
        Route::prefix('me')->group(function (): void {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::delete('/', [ProfileController::class, 'destroy']);
            Route::put('password', [ProfileController::class, 'changePassword']);
            Route::put('preferences', [ProfileController::class, 'preferences']);
            Route::post('avatar', [ProfileController::class, 'avatar']);

            // Two-factor management
            Route::post('two-factor/enable', [TwoFactorController::class, 'enable']);
            Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm']);
            Route::delete('two-factor', [TwoFactorController::class, 'disable']);

            // Sessions
            Route::get('sessions', [SessionController::class, 'index']);
            Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy']);
        });

        // ---- Admin (RBAC) ----
        Route::prefix('admin')->middleware('role:admin')->group(function (): void {
            Route::get('users', [UserAdminController::class, 'index']);
            Route::post('users/{userId}/roles', [UserAdminController::class, 'assignRole']);
            Route::delete('users/{userId}/roles', [UserAdminController::class, 'revokeRole']);
        });
    });
});
