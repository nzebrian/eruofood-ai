<?php

declare(strict_types=1);

use EruoFood\Loyalty\Interface\Http\Controller\LoyaltyAdminController;
use EruoFood\Loyalty\Interface\Http\Controller\LoyaltyController;
use EruoFood\Loyalty\Interface\Http\Controller\ReferralController;
use EruoFood\Loyalty\Interface\Http\Controller\RewardController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Loyalty routes — Loyalty, Rewards & Referrals (mounted under /api/v1 by the
| module provider). The tier ladder and the rewards catalogue are public to
| browse; a member's balance, ledger, redemptions and referral code require
| authentication; adjustments, reward management, fulfilment and analytics
| require an admin role (enforced in the controllers). Everything lives under
| "loyalty" so it never collides with other contexts. No business module awards
| or stores its own points — all loyalty flows through here, and tiers/vouchers
| flow out via published events.
|------------------------------------------------------------------------------
*/

$uuid = '[0-9a-fA-F-]{36}';

// ---- Public read surface ----
Route::prefix('v1/loyalty')->group(function (): void {
    Route::get('tiers', [LoyaltyController::class, 'tiers']);
    Route::get('rewards', [RewardController::class, 'index']);
});

Route::prefix('v1/loyalty')->middleware('auth.jwt')->group(function () use ($uuid): void {
    // ---- Member surface ----
    Route::get('me', [LoyaltyController::class, 'me']);
    Route::get('ledger', [LoyaltyController::class, 'ledger']);
    Route::get('redemptions', [RewardController::class, 'myRedemptions']);
    Route::post('rewards/{rewardId}/redeem', [RewardController::class, 'redeem'])->where('rewardId', $uuid);
    Route::post('redemptions/{id}/cancel', [RewardController::class, 'cancel'])->where('id', $uuid);

    // ---- Referrals ----
    Route::get('referrals/code', [ReferralController::class, 'myCode']);
    Route::post('referrals/apply', [ReferralController::class, 'apply']);

    // ---- Admin ----
    Route::post('admin/adjust', [LoyaltyAdminController::class, 'adjust']);
    Route::get('admin/rewards', [LoyaltyAdminController::class, 'listRewards']);
    Route::post('admin/rewards', [LoyaltyAdminController::class, 'createReward']);
    Route::put('admin/rewards/{id}', [LoyaltyAdminController::class, 'updateReward'])->where('id', $uuid);
    Route::post('admin/redemptions/fulfil', [LoyaltyAdminController::class, 'fulfil']);
    Route::get('admin/analytics', [LoyaltyAdminController::class, 'analytics']);
});
