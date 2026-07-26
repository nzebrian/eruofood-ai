import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/nutrition_entities.dart';

/// Nutrition repository contract (domain port).
abstract class NutritionRepository {
  Future<Either<Failure, HealthProfile?>> profile();

  Future<Either<Failure, HealthProfile>> saveProfile(Map<String, dynamic> payload);

  Future<Either<Failure, Assessment>> assessment();

  Future<Either<Failure, DailySummary>> diaryDay(String date);

  Future<Either<Failure, List<MealPlanView>>> mealPlans();

  Future<Either<Failure, List<ProgressPoint>>> progress();

  Future<Either<Failure, ProgressPoint>> recordProgress(Map<String, dynamic> payload);

  Future<Either<Failure, NutritionAdviceView>> mealRecommendations();

  Future<Either<Failure, NutritionAdviceView>> weeklyInsights();
}
