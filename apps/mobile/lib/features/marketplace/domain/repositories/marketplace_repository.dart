import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/marketplace_entities.dart';

/// Marketplace repository contract (domain port).
abstract class MarketplaceRepository {
  Future<Either<Failure, List<VendorSummary>>> vendors({String? query});

  Future<Either<Failure, List<MenuItemView>>> menu(String vendorId);

  Future<Either<Failure, CartView>> cart();

  Future<Either<Failure, CartView>> addToCart(String menuItemId, int quantity);

  Future<Either<Failure, OrderSummaryView>> checkout(Map<String, dynamic> payload);

  Future<Either<Failure, List<OrderSummaryView>>> orders();
}
