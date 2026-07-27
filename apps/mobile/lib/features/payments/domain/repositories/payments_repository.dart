import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/payments_entities.dart';

/// Contract for the Payments (wallet/checkout/history) feature.
abstract class PaymentsRepository {
  Future<Either<Failure, WalletView>> wallet();

  Future<Either<Failure, List<WalletTxnView>>> statement();

  Future<Either<Failure, PaymentView>> topUp(int amountMinor, String email);

  Future<Either<Failure, PaymentView>> pay(int amountMinor, String email, {String? orderId});

  Future<Either<Failure, List<PaymentView>>> payments();
}
