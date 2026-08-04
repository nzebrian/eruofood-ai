import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/food.dart';
import '../../domain/entities/recipe.dart';
import '../../domain/repositories/catalog_repository.dart';
import '../datasources/catalog_remote_data_source.dart';

class CatalogRepositoryImpl implements CatalogRepository {
  CatalogRepositoryImpl(this._remote);

  final CatalogRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<FoodSummary>>> foods({String? query, String? region}) =>
      _guard(() => _remote.foods(query: query, region: region));

  @override
  Future<Either<Failure, Food>> food(String slug) => _guard(() => _remote.food(slug));

  @override
  Future<Either<Failure, List<RecipeSummary>>> recipesForFood(String foodId) =>
      _guard(() => _remote.recipesForFood(foodId));

  @override
  Future<Either<Failure, List<RecipeSummary>>> recipes({String? query, String? difficulty}) =>
      _guard(() => _remote.recipes(query: query, difficulty: difficulty));

  @override
  Future<Either<Failure, Recipe>> recipe(String slug) => _guard(() => _remote.recipe(slug));

  @override
  Future<Either<Failure, List<RecipeSummary>>> favourites() => _guard(() => _remote.favourites());

  @override
  Future<Either<Failure, Unit>> addFavourite(String recipeId) =>
      _guard(() async {
        await _remote.addFavourite(recipeId);
        return unit;
      });

  @override
  Future<Either<Failure, Unit>> removeFavourite(String recipeId) =>
      _guard(() async {
        await _remote.removeFavourite(recipeId);
        return unit;
      });

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      return Left<Failure, T>(ServerFailure(e.message ?? 'Network error.'));
    }
  }
}
