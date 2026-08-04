import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/marketplace_entities.dart';
import '../../domain/repositories/marketplace_repository.dart';
import '../datasources/marketplace_remote_data_source.dart';

class MarketplaceRepositoryImpl implements MarketplaceRepository {
  MarketplaceRepositoryImpl(this._remote);

  final MarketplaceRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<VendorSummary>>> vendors({String? query}) =>
      _guard(() => _remote.vendors(query: query));

  @override
  Future<Either<Failure, List<MenuItemView>>> menu(String vendorId) => _guard(() => _remote.menu(vendorId));

  @override
  Future<Either<Failure, CartView>> cart() => _guard(() => _remote.cart());

  @override
  Future<Either<Failure, CartView>> addToCart(String menuItemId, int quantity) =>
      _guard(() => _remote.addToCart(menuItemId, quantity));

  @override
  Future<Either<Failure, OrderSummaryView>> checkout(Map<String, dynamic> payload) =>
      _guard(() => _remote.checkout(payload));

  @override
  Future<Either<Failure, List<OrderSummaryView>>> orders() => _guard(() => _remote.orders());

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
