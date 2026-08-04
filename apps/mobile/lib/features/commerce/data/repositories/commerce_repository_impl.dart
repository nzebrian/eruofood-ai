import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/commerce_entities.dart';
import '../../domain/repositories/commerce_repository.dart';
import '../datasources/commerce_remote_data_source.dart';

class CommerceRepositoryImpl implements CommerceRepository {
  CommerceRepositoryImpl(this._remote);

  final CommerceRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<ProductSummary>>> products({String? query, String? department}) =>
      _guard(() => _remote.products(query: query, department: department));

  @override
  Future<Either<Failure, CartView>> cart() => _guard(() => _remote.cart());

  @override
  Future<Either<Failure, CartView>> addToCart(String productId, int quantity, {String? variantSku}) =>
      _guard(() => _remote.addToCart(productId, quantity, variantSku: variantSku));

  @override
  Future<Either<Failure, CartView>> applyCoupon(String code) => _guard(() => _remote.applyCoupon(code));

  @override
  Future<Either<Failure, OrderSummaryView>> checkout(Map<String, dynamic> payload) =>
      _guard(() => _remote.checkout(payload));

  @override
  Future<Either<Failure, List<OrderSummaryView>>> orders() => _guard(() => _remote.orders());

  @override
  Future<Either<Failure, void>> addToWishlist(String productId) =>
      _guard(() => _remote.addToWishlist(productId));

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      final data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
