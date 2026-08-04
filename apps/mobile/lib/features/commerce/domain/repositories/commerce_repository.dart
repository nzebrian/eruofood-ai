import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/commerce_entities.dart';

/// Contract for the Commerce (Marketplace/Grocery) feature.
abstract class CommerceRepository {
  Future<Either<Failure, List<ProductSummary>>> products({String? query, String? department});

  Future<Either<Failure, CartView>> cart();

  Future<Either<Failure, CartView>> addToCart(String productId, int quantity, {String? variantSku});

  Future<Either<Failure, CartView>> applyCoupon(String code);

  Future<Either<Failure, OrderSummaryView>> checkout(Map<String, dynamic> payload);

  Future<Either<Failure, List<OrderSummaryView>>> orders();

  Future<Either<Failure, void>> addToWishlist(String productId);
}
