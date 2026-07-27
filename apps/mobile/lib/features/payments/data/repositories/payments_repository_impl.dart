import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/payments_entities.dart';
import '../../domain/repositories/payments_repository.dart';
import '../datasources/payments_remote_data_source.dart';

class PaymentsRepositoryImpl implements PaymentsRepository {
  PaymentsRepositoryImpl(this._remote);

  final PaymentsRemoteDataSource _remote;

  @override
  Future<Either<Failure, WalletView>> wallet() => _guard(() => _remote.wallet());

  @override
  Future<Either<Failure, List<WalletTxnView>>> statement() => _guard(() => _remote.statement());

  @override
  Future<Either<Failure, PaymentView>> topUp(int amountMinor, String email) =>
      _guard(() => _remote.topUp(amountMinor, email));

  @override
  Future<Either<Failure, PaymentView>> pay(int amountMinor, String email, {String? orderId}) =>
      _guard(() => _remote.pay(amountMinor, email, orderId: orderId));

  @override
  Future<Either<Failure, List<PaymentView>>> payments() => _guard(() => _remote.payments());

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      final data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
