import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/loyalty_entities.dart';
import '../../domain/repositories/loyalty_repository.dart';
import '../datasources/loyalty_remote_data_source.dart';

class LoyaltyRepositoryImpl implements LoyaltyRepository {
  LoyaltyRepositoryImpl(this._remote);

  final LoyaltyRemoteDataSource _remote;

  @override
  Future<Either<Failure, LoyaltyAccountView>> account() => _guard(() => _remote.account());

  @override
  Future<Either<Failure, List<LedgerEntryView>>> ledger() => _guard(() => _remote.ledger());

  @override
  Future<Either<Failure, List<RewardView>>> rewards() => _guard(() => _remote.rewards());

  @override
  Future<Either<Failure, RedemptionView>> redeem(String rewardId) => _guard(() => _remote.redeem(rewardId));

  @override
  Future<Either<Failure, String>> referralCode() => _guard(() => _remote.referralCode());

  @override
  Future<Either<Failure, Unit>> applyReferral(String code) => _guard(() async {
        await _remote.applyReferral(code);
        return unit;
      });

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() call) async {
    try {
      return Right<Failure, T>(await call());
    } on DioException catch (e) {
      final dynamic data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
