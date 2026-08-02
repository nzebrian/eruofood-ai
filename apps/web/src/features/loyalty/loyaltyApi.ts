import { apiClient } from '@lib/apiClient';
import type {
  LedgerEntry,
  LoyaltyAccount,
  LoyaltyAnalytics,
  Paginated,
  Redemption,
  ReferralCode,
  Reward,
  Tier,
} from './types';

/** Client for the Loyalty, Rewards & Referrals REST endpoints (mounted at /loyalty). */
export const loyaltyApi = {
  // Public
  tiers: () => apiClient.get<Tier[]>('/loyalty/tiers'),
  rewards: () => apiClient.getPage<Paginated<Reward>>('/loyalty/rewards'),

  // Member
  me: () => apiClient.get<LoyaltyAccount>('/loyalty/me'),
  ledger: () => apiClient.getPage<Paginated<LedgerEntry>>('/loyalty/ledger'),
  myRedemptions: () => apiClient.getPage<Paginated<Redemption>>('/loyalty/redemptions'),
  redeem: (rewardId: string) => apiClient.post<Redemption>(`/loyalty/rewards/${rewardId}/redeem`, {}),
  cancelRedemption: (id: string) => apiClient.post<Redemption>(`/loyalty/redemptions/${id}/cancel`, {}),

  // Referrals
  referralCode: () => apiClient.get<ReferralCode>('/loyalty/referrals/code'),
  applyReferral: (code: string) => apiClient.post<{ status: string }>('/loyalty/referrals/apply', { code }),

  // Admin
  adjust: (userId: string, points: number, reason: string) =>
    apiClient.post<LoyaltyAccount>('/loyalty/admin/adjust', { user_id: userId, points, reason }),
  analytics: () => apiClient.get<LoyaltyAnalytics>('/loyalty/admin/analytics'),
};
