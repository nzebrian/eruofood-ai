import 'package:equatable/equatable.dart';

/// A vendor in the browse list.
class VendorSummary extends Equatable {
  const VendorSummary({
    required this.id,
    required this.name,
    required this.slug,
    required this.category,
    required this.ratingAverage,
    required this.ratingCount,
    required this.featured,
  });

  final String id;
  final String name;
  final String slug;
  final String category;
  final double ratingAverage;
  final int ratingCount;
  final bool featured;

  @override
  List<Object?> get props => <Object?>[id, name, slug, category, ratingAverage, ratingCount, featured];
}

/// A menu item on a storefront.
class MenuItemView extends Equatable {
  const MenuItemView({
    required this.id,
    required this.name,
    required this.basePriceMinor,
    required this.currency,
    required this.orderable,
    this.description,
  });

  final String id;
  final String name;
  final int basePriceMinor;
  final String currency;
  final bool orderable;
  final String? description;

  @override
  List<Object?> get props => <Object?>[id, name, basePriceMinor, currency, orderable, description];
}

/// A line in the cart.
class CartLineView extends Equatable {
  const CartLineView({required this.name, required this.quantity, required this.lineTotalMinor});

  final String name;
  final int quantity;
  final int lineTotalMinor;

  @override
  List<Object?> get props => <Object?>[name, quantity, lineTotalMinor];
}

/// The shopping cart.
class CartView extends Equatable {
  const CartView({required this.currency, required this.items, required this.subtotalMinor});

  final String currency;
  final List<CartLineView> items;
  final int subtotalMinor;

  bool get isEmpty => items.isEmpty;

  @override
  List<Object?> get props => <Object?>[currency, items, subtotalMinor];
}

/// An order summary in history.
class OrderSummaryView extends Equatable {
  const OrderSummaryView({
    required this.id,
    required this.reference,
    required this.status,
    required this.fulfilment,
    required this.totalMinor,
    required this.currency,
  });

  final String id;
  final String reference;
  final String status;
  final String fulfilment;
  final int totalMinor;
  final String currency;

  @override
  List<Object?> get props => <Object?>[id, reference, status, fulfilment, totalMinor, currency];
}

/// Formats minor units (kobo) as Naira.
String formatMoney(int minor, [String currency = 'NGN']) {
  final symbol = currency == 'NGN' ? '₦' : '';
  return '$symbol${(minor / 100).toStringAsFixed(0)}';
}
