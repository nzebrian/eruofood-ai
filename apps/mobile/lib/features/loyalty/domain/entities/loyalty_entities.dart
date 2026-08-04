import 'package:equatable/equatable.dart';

/// A membership tier.
class TierView extends Equatable {
  const TierView({required this.key, required this.name});

  final String key;
  final String name;

  @override
  List<Object?> get props => <Object?>[key, name];
}

/// The next tier a member is working toward.
class NextTierView extends Equatable {
  const NextTierView({required this.name, required this.pointsToGo});

  final String name;
  final int pointsToGo;

  @override
  List<Object?> get props => <Object?>[name, pointsToGo];
}

/// A member's loyalty account.
class LoyaltyAccountView extends Equatable {
  const LoyaltyAccountView({
    required this.balance,
    required this.lifetimePoints,
    required this.tier,
    required this.nextTier,
  });

  final int balance;
  final int lifetimePoints;
  final TierView tier;
  final NextTierView? nextTier;

  @override
  List<Object?> get props => <Object?>[balance, lifetimePoints, tier, nextTier];
}

/// One movement on the points ledger.
class LedgerEntryView extends Equatable {
  const LedgerEntryView({
    required this.id,
    required this.type,
    required this.points,
    required this.reason,
    required this.createdAt,
  });

  final String id;
  final String type;
  final int points;
  final String reason;
  final String createdAt;

  @override
  List<Object?> get props => <Object?>[id, type, points, reason, createdAt];
}

/// A catalogue reward.
class RewardView extends Equatable {
  const RewardView({
    required this.id,
    required this.name,
    required this.description,
    required this.pointsCost,
  });

  final String id;
  final String name;
  final String description;
  final int pointsCost;

  @override
  List<Object?> get props => <Object?>[id, name, description, pointsCost];
}

/// An issued redemption voucher.
class RedemptionView extends Equatable {
  const RedemptionView({
    required this.id,
    required this.code,
    required this.pointsSpent,
    required this.status,
  });

  final String id;
  final String code;
  final int pointsSpent;
  final String status;

  @override
  List<Object?> get props => <Object?>[id, code, pointsSpent, status];
}
