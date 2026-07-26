import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/nutrition_entities.dart';
import '../../domain/repositories/nutrition_repository.dart';
import '../datasources/nutrition_remote_data_source.dart';

class NutritionRepositoryImpl implements NutritionRepository {
  NutritionRepositoryImpl(this._remote);

  final NutritionRemoteDataSource _remote;

  @override
  Future<Either<Failure, HealthProfile?>> profile() => _guard(() => _remote.profile());

  @override
  Future<Either<Failure, HealthProfile>> saveProfile(Map<String, dynamic> payload) =>
      _guard(() => _remote.saveProfile(payload));

  @override
  Future<Either<Failure, Assessment>> assessment() => _guard(() => _remote.assessment());

  @override
  Future<Either<Failure, DailySummary>> diaryDay(String date) => _guard(() => _remote.diaryDay(date));

  @override
  Future<Either<Failure, List<MealPlanView>>> mealPlans() => _guard(() => _remote.mealPlans());

  @override
  Future<Either<Failure, List<ProgressPoint>>> progress() => _guard(() => _remote.progress());

  @override
  Future<Either<Failure, ProgressPoint>> recordProgress(Map<String, dynamic> payload) =>
      _guard(() => _remote.recordProgress(payload));

  @override
  Future<Either<Failure, NutritionAdviceView>> mealRecommendations() =>
      _guard(() => _remote.mealRecommendations());

  @override
  Future<Either<Failure, NutritionAdviceView>> weeklyInsights() => _guard(() => _remote.weeklyInsights());

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      final message = e.response?.data is Map<String, dynamic>
          ? ((e.response!.data as Map<String, dynamic>)['error']?['message']?.toString() ??
              e.message ??
              'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
