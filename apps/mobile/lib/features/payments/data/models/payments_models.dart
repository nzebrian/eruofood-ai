import '../../domain/entities/payments_entities.dart';

WalletView walletFromJson(Map<String, dynamic> json) {
  return WalletView(
    id: json['id'] as String? ?? '',
    balanceMinor: (json['balance_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'NGN',
  );
}

WalletTxnView walletTxnFromJson(Map<String, dynamic> json) {
  return WalletTxnView(
    id: json['id'] as String? ?? '',
    type: json['type'] as String? ?? '',
    direction: json['direction'] as String? ?? 'credit',
    amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 0,
    balanceAfterMinor: (json['balance_after_minor'] as num?)?.toInt() ?? 0,
    description: json['description'] as String?,
  );
}

PaymentView paymentFromJson(Map<String, dynamic> json) {
  return PaymentView(
    id: (json['id'] ?? json['payment_id']) as String? ?? '',
    reference: json['reference'] as String? ?? '',
    amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'NGN',
    status: json['status'] as String? ?? 'pending',
    provider: json['provider'] as String? ?? 'mock',
    authorizationUrl: json['authorization_url'] as String?,
  );
}
