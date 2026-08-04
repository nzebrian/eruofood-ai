import 'package:equatable/equatable.dart';

/// A product in the shop/grocery listing.
class ProductSummary extends Equatable {
  const ProductSummary({
    required this.id,
    required this.name,
    required this.slug,
    required this.kind,
    required this.basePriceMinor,
    required this.currency,
    required this.ratingAverage,
    required this.ratingCount,
    required this.featured,
    this.department,
  });

  final String id;
  final String name;
  final String slug;
  final String kind; // grocery | general
  final String? department;
  final int basePriceMinor;
  final String currency;
  final double ratingAverage;
  final int ratingCount;
  final bool featured;

  @override
  List<Object?> get props =>
      <Object?>[id, name, slug, kind, department, basePriceMinor, currency, ratingAverage, ratingCount, featured];
}

/// A line in the shopping cart.
class CartLineView extends Equatable {
  const CartLineView({
    required this.productId,
    required this.name,
    required this.quantity,
    required this.lineTotalMinor,
    this.variantSku,
  });

  final String productId;
  final String? variantSku;
  final String name;
  final int quantity;
  final int lineTotalMinor;

  @override
  List<Object?> get props => <Object?>[productId, variantSku, name, quantity, lineTotalMinor];
}

/// The shopping cart.
class CartView extends Equatable {
  const CartView({
    required this.currency,
    required this.subtotalMinor,
    required this.itemCount,
    required this.couponCode,
    required this.items,
  });

  final String currency;
  final int subtotalMinor;
  final int itemCount;
  final String? couponCode;
  final List<CartLineView> items;

  @override
  List<Object?> get props => <Object?>[currency, subtotalMinor, itemCount, couponCode, items];
}

/// A placed order summary.
class OrderSummaryView extends Equatable {
  const OrderSummaryView({
    required this.id,
    required this.reference,
    required this.status,
    required this.totalMinor,
    required this.currency,
  });

  final String id;
  final String reference;
  final String status;
  final int totalMinor;
  final String currency;

  @override
  List<Object?> get props => <Object?>[id, reference, status, totalMinor, currency];
}
