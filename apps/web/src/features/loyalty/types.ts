/** Types for the Loyalty, Rewards & Referrals module. */

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface Tier {
  key: string;
  name: string;
  threshold: number;
  earn_multiplier: number;
}

export interface NextTier {
  key: string;
  name: string;
  points_to_go: number;
}

export interface LoyaltyAccount {
  user_id: string;
  balance: number;
  lifetime_points: number;
  tier: Tier | { key: string };
  next_tier: NextTier | null;
  updated_at: string;
}

export type LedgerEntryType = 'earn' | 'redeem' | 'expire' | 'adjust';

export interface LedgerEntry {
  id: string;
  type: LedgerEntryType;
  points: number;
  reason: string;
  reference: string | null;
  balance_after: number;
  created_at: string;
  expires_at: string | null;
}

export interface Reward {
  id: string;
  name: string;
  description: string;
  benefit_type: string;
  benefit_value: number;
  points_cost: number;
  stock: number | null;
  active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string;
}

export type RedemptionStatus = 'issued' | 'fulfilled' | 'cancelled';

export interface Redemption {
  id: string;
  reward_id: string;
  user_id: string;
  code: string;
  points_spent: number;
  benefit_type: string;
  benefit_value: number;
  status: RedemptionStatus;
  created_at: string;
  updated_at: string;
}

export interface ReferralCode {
  code: string;
  user_id: string;
  created_at: string;
}

export interface LoyaltyAnalytics {
  members: number;
  points_outstanding: number;
  points_by_type: Record<string, number>;
  members_by_tier: Record<string, number>;
  top_rewards: { reward_id: string; redemptions: number; points: number }[];
}
