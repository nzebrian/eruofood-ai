import 'package:equatable/equatable.dart';

/// The user's wallet.
class WalletView extends Equatable {
  const WalletView({required this.id, required this.balanceMinor, required this.currency});

  final String id;
  final int balanceMinor;
  final String currency;

  @override
  List<Object?> get props => <Object?>[id, balanceMinor, currency];
}

/// A wallet statement line.
class WalletTxnView extends Equatable {
  const WalletTxnView({
    required this.id,
    required this.type,
    required this.direction,
    required this.amountMinor,
    required this.balanceAfterMinor,
    this.description,
  });

  final String id;
  final String type;
  final String direction; // credit | debit
  final int amountMinor;
  final int balanceAfterMinor;
  final String? description;

  @override
  List<Object?> get props =>
      <Object?>[id, type, direction, amountMinor, balanceAfterMinor, description];
}

/// A payment in the history / the result of initiating one.
class PaymentView extends Equatable {
  const PaymentView({
    required this.id,
    required this.reference,
    required this.amountMinor,
    required this.currency,
    required this.status,
    required this.provider,
    this.authorizationUrl,
  });

  final String id;
  final String reference;
  final int amountMinor;
  final String currency;
  final String status;
  final String provider;
  final String? authorizationUrl;

  @override
  List<Object?> get props =>
      <Object?>[id, reference, amountMinor, currency, status, provider, authorizationUrl];
}
