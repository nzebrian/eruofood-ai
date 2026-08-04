import '../../domain/entities/marketplace_entities.dart';

/// JSON mappers for the marketplace API payloads.
VendorSummary vendorSummaryFromJson(Map<String, dynamic> json) {
  return VendorSummary(
    id: json['id'] as String,
    name: json['name'] as String? ?? 'Vendor',
    slug: json['slug'] as String? ?? '',
    category: json['category'] as String? ?? 'general',
    ratingAverage: (json['rating_average'] as num?)?.toDouble() ?? 0,
    ratingCount: (json['rating_count'] as num?)?.toInt() ?? 0,
    featured: json['featured'] as bool? ?? false,
  );
}

MenuItemView menuItemFromJson(Map<String, dynamic> json) {
  return MenuItemView(
    id: json['id'] as String,
    name: json['name'] as String? ?? 'Item',
    basePriceMinor: (json['base_price_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'NGN',
    orderable: json['orderable'] as bool? ?? false,
    description: json['description'] as String?,
  );
}

CartView cartFromJson(Map<String, dynamic> json) {
  final items = (json['items'] as List<dynamic>?) ?? <dynamic>[];
  return CartView(
    currency: json['currency'] as String? ?? 'NGN',
    subtotalMinor: (json['subtotal_minor'] as num?)?.toInt() ?? 0,
    items: items.map((dynamic e) {
      final row = e as Map<String, dynamic>;
      return CartLineView(
        name: row['name'] as String? ?? '',
        quantity: (row['quantity'] as num?)?.toInt() ?? 0,
        lineTotalMinor: (row['line_total_minor'] as num?)?.toInt() ?? 0,
      );
    }).toList(),
  );
}

OrderSummaryView orderSummaryFromJson(Map<String, dynamic> json) {
  return OrderSummaryView(
    id: json['id'] as String,
    reference: json['reference'] as String? ?? '',
    status: json['status'] as String? ?? 'pending',
    fulfilment: json['fulfilment'] as String? ?? 'delivery',
    totalMinor: (json['total_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'NGN',
  );
}
