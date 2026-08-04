import '../../domain/entities/commerce_entities.dart';

/// JSON mappers for the Commerce API payloads.
ProductSummary productSummaryFromJson(Map<String, dynamic> json) {
  return ProductSummary(
    id: json['id'] as String,
    name: json['name'] as String? ?? 'Product',
    slug: json['slug'] as String? ?? '',
    kind: json['kind'] as String? ?? 'general',
    department: json['department'] as String?,
    basePriceMinor: (json['base_price_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'NGN',
    ratingAverage: (json['rating_average'] as num?)?.toDouble() ?? 0,
    ratingCount: (json['rating_count'] as num?)?.toInt() ?? 0,
    featured: json['featured'] as bool? ?? false,
  );
}

CartView cartFromJson(Map<String, dynamic> json) {
  final items = (json['items'] as List<dynamic>?) ?? <dynamic>[];
  return CartView(
    currency: json['currency'] as String? ?? 'NGN',
    subtotalMinor: (json['subtotal_minor'] as num?)?.toInt() ?? 0,
    itemCount: (json['item_count'] as num?)?.toInt() ?? 0,
    couponCode: json['coupon_code'] as String?,
    items: items.map((dynamic e) {
      final row = e as Map<String, dynamic>;
      return CartLineView(
        productId: row['product_id'] as String? ?? '',
        variantSku: row['variant_sku'] as String?,
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
    totalMinor: (json['total_minor'] as num?)?.toInt() ?? 0,
    currency: json['currency'] as String? ?? 'NGN',
  );
}
