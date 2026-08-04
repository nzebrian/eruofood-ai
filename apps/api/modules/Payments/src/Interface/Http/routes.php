<?php

declare(strict_types=1);

use EruoFood\Payments\Interface\Http\Controller\Admin\FinanceAdminController;
use EruoFood\Payments\Interface\Http\Controller\Admin\SettlementAdminController;
use EruoFood\Payments\Interface\Http\Controller\PaymentController;
use EruoFood\Payments\Interface\Http\Controller\RefundController;
use EruoFood\Payments\Interface\Http\Controller\SavedMethodController;
use EruoFood\Payments\Interface\Http\Controller\SubscriptionController;
use EruoFood\Payments\Interface\Http\Controller\WalletController;
use EruoFood\Payments\Interface\Http\Controller\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Payments routes — Payments, Wallet & Financial Services
| (mounted under /api/v1 by the module provider). All paths are under
| "payments" so they never collide with other contexts.
|------------------------------------------------------------------------------
*/

Route::prefix('v1/payments')->group(function (): void {
    // ---- Provider webhooks (public; the provider signs the payload) ----
    Route::post('webhooks/{provider}', [WebhookController::class, 'handle'])->middleware('throttle:120,1');

    // ---- Authenticated (customer) ----
    Route::middleware('auth.jwt')->group(function (): void {
        // Payments
        Route::post('payments', [PaymentController::class, 'store']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{id}', [PaymentController::class, 'show']);
        Route::post('payments/{id}/verify', [PaymentController::class, 'verify']);
        Route::post('payments/{id}/cancel', [PaymentController::class, 'cancel']);

        // Refunds
        Route::post('refunds', [RefundController::class, 'store']);
        Route::get('payments/{paymentId}/refunds', [RefundController::class, 'forPayment']);

        // Wallet
        Route::get('wallet', [WalletController::class, 'show']);
        Route::get('wallet/statement', [WalletController::class, 'statement']);
        Route::post('wallet/topup', [WalletController::class, 'topUp']);
        Route::post('wallet/transfer', [WalletController::class, 'transfer']);

        // Saved payment methods
        Route::get('methods', [SavedMethodController::class, 'index']);
        Route::post('methods', [SavedMethodController::class, 'store']);
        Route::post('methods/{id}/default', [SavedMethodController::class, 'makeDefault']);
        Route::delete('methods/{id}', [SavedMethodController::class, 'destroy']);

        // Subscriptions
        Route::get('subscriptions', [SubscriptionController::class, 'index']);
        Route::post('subscriptions', [SubscriptionController::class, 'store']);
        Route::post('subscriptions/{id}/cancel', [SubscriptionController::class, 'cancel']);
    });

    // ---- Admin (RBAC) ----
    Route::middleware(['auth.jwt', 'role:admin'])->prefix('admin')->group(function (): void {
        Route::get('payments', [FinanceAdminController::class, 'payments']);
        Route::get('refunds', [FinanceAdminController::class, 'refunds']);
        Route::get('report', [FinanceAdminController::class, 'report']);
        Route::get('providers', [FinanceAdminController::class, 'providers']);

        Route::post('settlements', [SettlementAdminController::class, 'settle']);
        Route::get('settlements', [SettlementAdminController::class, 'index']);
        Route::get('payouts', [SettlementAdminController::class, 'payouts']);
    });
});
