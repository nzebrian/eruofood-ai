import '../../../../core/network/api_client.dart';
import '../../domain/entities/marketplace_entities.dart';
import '../models/marketplace_models.dart';

/// Reads the marketplace REST endpoints via the shared ApiClient.
class MarketplaceRemoteDataSource {
  MarketplaceRemoteDataSource(this._client);

  final ApiClient _client;

  Map<String, dynamic> _item(dynamic res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  List<T> _list<T>(dynamic res, T Function(Map<String, dynamic>) map) {
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data.map((dynamic e) => map(e as Map<String, dynamic>)).toList();
  }

  Future<List<VendorSummary>> vendors({String? query}) async {
    final res = await _client.get<dynamic>('/vendors', query: <String, dynamic>{
      if (query != null && query.isNotEmpty) 'q': query,
      'per_page': 30,
    });
    return _list(res, vendorSummaryFromJson);
  }

  Future<List<MenuItemView>> menu(String vendorId) async {
    final res = await _client.get<dynamic>('/vendors/$vendorId/menu');
    return _list(res, menuItemFromJson);
  }

  Future<CartView> cart() async {
    final res = await _client.get<dynamic>('/cart');
    return cartFromJson(_item(res));
  }

  Future<CartView> addToCart(String menuItemId, int quantity) async {
    final res = await _client.post<dynamic>('/cart/items', data: <String, dynamic>{
      'menu_item_id': menuItemId,
      'quantity': quantity,
    });
    return cartFromJson(_item(res));
  }

  Future<OrderSummaryView> checkout(Map<String, dynamic> payload) async {
    final res = await _client.post<dynamic>('/checkout', data: payload);
    return orderSummaryFromJson(_item(res));
  }

  Future<List<OrderSummaryView>> orders() async {
    final res = await _client.get<dynamic>('/orders');
    return _list(res, orderSummaryFromJson);
  }
}
