import 'package:dio/dio.dart';

import '../../../../core/network/api_client.dart';
import '../models/food_model.dart';
import '../models/recipe_model.dart';

/// Reads the Catalog REST endpoints via the shared ApiClient.
class CatalogRemoteDataSource {
  CatalogRemoteDataSource(this._client);

  final ApiClient _client;

  List<T> _list<T>(Response<dynamic> res, T Function(Map<String, dynamic>) map) {
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data.map((dynamic e) => map(e as Map<String, dynamic>)).toList();
  }

  Map<String, dynamic> _item(Response<dynamic> res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  Future<List<FoodSummaryModel>> foods({String? query, String? region}) async {
    final res = await _client.get<dynamic>('/foods', query: <String, dynamic>{
      if (query != null && query.isNotEmpty) 'q': query,
      if (region != null && region.isNotEmpty) 'region': region,
    });
    return _list(res, FoodSummaryModel.fromJson);
  }

  Future<FoodModel> food(String slug) async {
    final res = await _client.get<dynamic>('/foods/$slug');
    return FoodModel.fromJson(_item(res));
  }

  Future<List<RecipeSummaryModel>> recipesForFood(String foodId) async {
    final res = await _client.get<dynamic>('/foods/$foodId/recipes');
    return _list(res, RecipeSummaryModel.fromJson);
  }

  Future<List<RecipeSummaryModel>> recipes({String? query, String? difficulty}) async {
    final res = await _client.get<dynamic>('/recipes', query: <String, dynamic>{
      if (query != null && query.isNotEmpty) 'q': query,
      if (difficulty != null && difficulty.isNotEmpty) 'difficulty': difficulty,
    });
    return _list(res, RecipeSummaryModel.fromJson);
  }

  Future<RecipeModel> recipe(String slug) async {
    final res = await _client.get<dynamic>('/recipes/$slug');
    return RecipeModel.fromJson(_item(res));
  }

  Future<List<RecipeSummaryModel>> favourites() async {
    final res = await _client.get<dynamic>('/me/favourites');
    return _list(res, RecipeSummaryModel.fromJson);
  }

  Future<void> addFavourite(String recipeId) => _client.post<dynamic>('/me/favourites/$recipeId');

  Future<void> removeFavourite(String recipeId) =>
      _client.raw.delete<dynamic>('/me/favourites/$recipeId');
}
