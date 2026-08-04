import '../../../../core/network/api_client.dart';
import '../../domain/entities/payments_entities.dart';
import '../models/payments_models.dart';

/// Reads the Payments REST endpoints (mounted at /payments) via the ApiClient.
class PaymentsRemoteDataSource {
  PaymentsRemoteDataSource(this._client);

  final ApiClient _client;

  Map<String, dynamic> _item(dynamic res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  List<T> _list<T>(dynamic res, T Function(Map<String, dynamic>) map) {
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data.map((dynamic e) => map(e as Map<String, dynamic>)).toList();
  }

  Future<WalletView> wallet() async {
    final res = await _client.get<dynamic>('/payments/wallet');
    return walletFromJson(_item(res));
  }

  Future<List<WalletTxnView>> statement() async {
    final res = await _client.get<dynamic>('/payments/wallet/statement');
    return _list(res, walletTxnFromJson);
  }

  Future<PaymentView> topUp(int amountMinor, String email) async {
    final res = await _client.post<dynamic>('/payments/wallet/topup', data: <String, dynamic>{
      'amount_minor': amountMinor,
      'customer_email': email,
    });
    return paymentFromJson(_item(res));
  }

  Future<PaymentView> pay(int amountMinor, String email, {String? orderId}) async {
    final res = await _client.post<dynamic>('/payments/payments', data: <String, dynamic>{
      'amount_minor': amountMinor,
      'customer_email': email,
      if (orderId != null) 'order_id': orderId,
    });
    return paymentFromJson(_item(res));
  }

  Future<List<PaymentView>> payments() async {
    final res = await _client.get<dynamic>('/payments/payments');
    return _list(res, paymentFromJson);
  }
}
