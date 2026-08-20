<?php

declare(strict_types=1);

use EruoFood\Payments\Interface\Http\Controller\Admin\FinanceAdminController;
use EruoFood\Payments\Interface\Http\Controller\Admin\ReconciliationAdminController;
use EruoFood\Payments\Interface\Http\Controller\Admin\SettlementAdminController;
use EruoFood\Payments\Interface\Http\Controller\Admin\SettlementRunAdminController;
use EruoFood\Payments\Interface\Http\Controller\MerchantSettlementController;
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

        // Merchant self-service (M27). Read-only, and every route scoped to the
        // merchants the authenticated user actually owns — an unowned id is a
        // 404, not a 403, so ids cannot be enumerated.
        Route::get('merchants/{merchantId}/payable', [MerchantSettlementController::class, 'payable']);
        Route::get('merchants/{merchantId}/accruals', [MerchantSettlementController::class, 'accruals']);
        Route::get('merchants/{merchantId}/settlements', [MerchantSettlementController::class, 'settlements']);
        Route::get('merchants/{merchantId}/settlements/{id}', [MerchantSettlementController::class, 'show']);
    });

    // ---- Admin (RBAC) ----
    // The permissions are named as strings, not imported from the Admin module —
    // the `permission:` middleware alias is the published contract between the
    // contexts, so Payments takes no compile-time dependency on Admin.
    //
    // Grouped by *consequence*, not by controller. Everything that only reads
    // sits under `finance.read`; everything that moves money, or changes what
    // the books say, sits under a permission whose name admits it. The previous
    // arrangement put `POST admin/settlements` — a bank transfer — inside the
    // read group, which is the reason this split exists.

    // Read-only: sees money, moves none.
    Route::middleware(['auth.jwt', 'permission:finance.read'])->prefix('admin')->group(function (): void {
        Route::get('payments', [FinanceAdminController::class, 'payments']);
        Route::get('refunds', [FinanceAdminController::class, 'refunds']);
        Route::get('report', [FinanceAdminController::class, 'report']);
        Route::get('providers', [FinanceAdminController::class, 'providers']);

        Route::get('settlements', [SettlementAdminController::class, 'index']);
        Route::get('payouts', [SettlementAdminController::class, 'payouts']);

        // M27 read surfaces. Note the ordering: the literal `settlement-runs`
        // collection route is declared before `{id}`, so "settlement-health"
        // cannot be captured as a run id.
        Route::get('payables', [SettlementRunAdminController::class, 'payables']);
        Route::get('settlement-health', [SettlementRunAdminController::class, 'health']);
        Route::get('payout-attempts', [SettlementRunAdminController::class, 'attempts']);
        Route::get('settlement-runs', [SettlementRunAdminController::class, 'index']);
        Route::get('settlement-runs/{id}', [SettlementRunAdminController::class, 'show']);
    });

    // Decides that a computed settlement is correct. Moves nothing itself.
    Route::middleware(['auth.jwt', 'permission:finance.settle'])->prefix('admin')->group(function (): void {
        Route::post('settlement-runs', [SettlementRunAdminController::class, 'compute']);
        Route::post('settlement-runs/{id}/approve', [SettlementRunAdminController::class, 'approve']);
        Route::post('settlement-runs/{id}/cancel', [SettlementRunAdminController::class, 'cancel']);

        // The legacy M22 settlement path. Retained for compatibility, but no
        // longer reachable with a read permission.
        Route::post('settlements', [SettlementAdminController::class, 'settle']);
    });

    // Performs the transfer. Separate permission, and the aggregate separately
    // refuses the person who approved it.
    Route::middleware(['auth.jwt', 'permission:finance.payout'])->prefix('admin')->group(function (): void {
        Route::post('settlement-runs/{id}/execute', [SettlementRunAdminController::class, 'execute']);
        Route::post('settlement-runs/{id}/retry', [SettlementRunAdminController::class, 'retry']);
    });

    // Investigates discrepancies. Closes nothing that changes a number.
    Route::middleware(['auth.jwt', 'permission:finance.reconcile'])->prefix('admin')->group(function (): void {
        Route::get('reconciliation-cases', [ReconciliationAdminController::class, 'index']);
        Route::get('reconciliation-cases/{id}', [ReconciliationAdminController::class, 'show']);
        Route::post('reconciliation-cases/{id}/investigate', [ReconciliationAdminController::class, 'investigate']);
        Route::post('reconciliation-cases/{id}/escalate', [ReconciliationAdminController::class, 'escalate']);
        Route::post('settlement-runs/{id}/reconcile', [SettlementRunAdminController::class, 'reconcile']);
    });

    // Changes what the books say. SuperAdmin only.
    Route::middleware(['auth.jwt', 'permission:finance.adjust'])->prefix('admin')->group(function (): void {
        Route::post('reconciliation-cases/{id}/resolve', [ReconciliationAdminController::class, 'resolve']);
    });

    // Claws back a completed payout. SuperAdmin only.
    Route::middleware(['auth.jwt', 'permission:finance.reverse'])->prefix('admin')->group(function (): void {
        Route::post('settlement-runs/{id}/reverse', [SettlementRunAdminController::class, 'reverse']);
    });
});
