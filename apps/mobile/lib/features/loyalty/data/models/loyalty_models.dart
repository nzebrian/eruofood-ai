import '../../domain/entities/loyalty_entities.dart';

TierView tierFromJson(Map<String, dynamic> json) {
  return TierView(
    key: json['key'] as String? ?? 'bronze',
    name: json['name'] as String? ?? 'Bronze',
  );
}

NextTierView? nextTierFromJson(Map<String, dynamic>? json) {
  if (json == null) return null;
  return NextTierView(
    name: json['name'] as String? ?? '',
    pointsToGo: (json['points_to_go'] as num?)?.toInt() ?? 0,
  );
}

LoyaltyAccountView accountFromJson(Map<String, dynamic> json) {
  return LoyaltyAccountView(
    balance: (json['balance'] as num?)?.toInt() ?? 0,
    lifetimePoints: (json['lifetime_points'] as num?)?.toInt() ?? 0,
    tier: tierFromJson(json['tier'] as Map<String, dynamic>? ?? <String, dynamic>{}),
    nextTier: nextTierFromJson(json['next_tier'] as Map<String, dynamic>?),
  );
}

LedgerEntryView ledgerEntryFromJson(Map<String, dynamic> json) {
  return LedgerEntryView(
    id: json['id'] as String? ?? '',
    type: json['type'] as String? ?? 'earn',
    points: (json['points'] as num?)?.toInt() ?? 0,
    reason: json['reason'] as String? ?? '',
    createdAt: json['created_at'] as String? ?? '',
  );
}

RewardView rewardFromJson(Map<String, dynamic> json) {
  return RewardView(
    id: json['id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    description: json['description'] as String? ?? '',
    pointsCost: (json['points_cost'] as num?)?.toInt() ?? 0,
  );
}

RedemptionView redemptionFromJson(Map<String, dynamic> json) {
  return RedemptionView(
    id: json['id'] as String? ?? '',
    code: json['code'] as String? ?? '',
    pointsSpent: (json['points_spent'] as num?)?.toInt() ?? 0,
    status: json['status'] as String? ?? 'issued',
  );
}
