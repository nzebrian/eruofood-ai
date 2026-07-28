import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/analytics_entities.dart';
import '../../domain/repositories/analytics_repository.dart';
import '../datasources/analytics_remote_data_source.dart';

class AnalyticsRepositoryImpl implements AnalyticsRepository {
  AnalyticsRepositoryImpl(this._remote);

  final AnalyticsRemoteDataSource _remote;

  @override
  Future<Either<Failure, DashboardView>> dashboard(String type, int days) async {
    try {
      return Right<Failure, DashboardView>(await _remote.dashboard(type, days));
    } on DioException catch (e) {
      final data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, DashboardView>(ServerFailure(message));
    }
  }
}
