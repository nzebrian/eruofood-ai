import '../../../../core/network/api_client.dart';
import '../../domain/entities/commerce_entities.dart';
import '../models/commerce_models.dart';

/// Reads the Commerce REST endpoints (mounted at /commerce) via the ApiClient.
class CommerceRemoteDataSource {
  CommerceRemoteDataSource(this._client);

  final ApiClient _client;

  Map<String, dynamic> _item(dynamic res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  List<T> _list<T>(dynamic res, T Function(Map<String, dynamic>) map) {
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data.map((dynamic e) => map(e as Map<String, dynamic>)).toList();
  }

  Future<List<ProductSummary>> products({String? query, String? department}) async {
    final res = await _client.get<dynamic>('/commerce/products', query: <String, dynamic>{
      if (query != null && query.isNotEmpty) 'q': query,
      if (department != null && department.isNotEmpty) 'department': department,
      'per_page': 30,
    });
    return _list(res, productSummaryFromJson);
  }

  Future<CartView> cart() async {
    final res = await _client.get<dynamic>('/commerce/cart');
    return cartFromJson(_item(res));
  }

  Future<CartView> addToCart(String productId, int quantity, {String? variantSku}) async {
    final res = await _client.post<dynamic>('/commerce/cart/items', data: <String, dynamic>{
      'product_id': productId,
      'quantity': quantity,
      if (variantSku != null) 'variant_sku': variantSku,
    });
    return cartFromJson(_item(res));
  }

  Future<CartView> applyCoupon(String code) async {
    final res = await _client.post<dynamic>('/commerce/cart/coupon', data: <String, dynamic>{'code': code});
    return cartFromJson(_item(res));
  }

  Future<OrderSummaryView> checkout(Map<String, dynamic> payload) async {
    final res = await _client.post<dynamic>('/commerce/checkout', data: payload);
    return orderSummaryFromJson(_item(res));
  }

  Future<List<OrderSummaryView>> orders() async {
    final res = await _client.get<dynamic>('/commerce/orders');
    return _list(res, orderSummaryFromJson);
  }

  Future<void> addToWishlist(String productId) async {
    await _client.post<dynamic>('/commerce/wishlist', data: <String, dynamic>{'product_id': productId});
  }
}
