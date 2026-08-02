import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/loyalty_entities.dart';

/// Contract for the Loyalty, Rewards & Referrals feature. Every points
/// interaction flows through the Loyalty context — no other feature awards or
/// stores points.
abstract class LoyaltyRepository {
  Future<Either<Failure, LoyaltyAccountView>> account();

  Future<Either<Failure, List<LedgerEntryView>>> ledger();

  Future<Either<Failure, List<RewardView>>> rewards();

  Future<Either<Failure, RedemptionView>> redeem(String rewardId);

  Future<Either<Failure, String>> referralCode();

  Future<Either<Failure, Unit>> applyReferral(String code);
}
