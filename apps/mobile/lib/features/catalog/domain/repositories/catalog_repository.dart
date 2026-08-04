import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/food.dart';
import '../entities/recipe.dart';

/// Catalog repository contract (domain port).
abstract class CatalogRepository {
  Future<Either<Failure, List<FoodSummary>>> foods({String? query, String? region});

  Future<Either<Failure, Food>> food(String slug);

  Future<Either<Failure, List<RecipeSummary>>> recipesForFood(String foodId);

  Future<Either<Failure, List<RecipeSummary>>> recipes({String? query, String? difficulty});

  Future<Either<Failure, Recipe>> recipe(String slug);

  Future<Either<Failure, List<RecipeSummary>>> favourites();

  Future<Either<Failure, Unit>> addFavourite(String recipeId);

  Future<Either<Failure, Unit>> removeFavourite(String recipeId);
}
