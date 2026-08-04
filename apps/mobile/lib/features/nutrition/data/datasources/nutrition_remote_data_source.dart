import 'package:dio/dio.dart';

import '../../../../core/network/api_client.dart';
import '../../domain/entities/nutrition_entities.dart';
import '../models/nutrition_models.dart';

/// Reads the Nutrition REST endpoints via the shared ApiClient.
class NutritionRemoteDataSource {
  NutritionRemoteDataSource(this._client);

  final ApiClient _client;

  Map<String, dynamic>? _itemOrNull(Response<dynamic> res) {
    final data = (res.data as Map<String, dynamic>)['data'];
    return data is Map<String, dynamic> ? data : null;
  }

  Map<String, dynamic> _item(Response<dynamic> res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  List<T> _list<T>(Response<dynamic> res, T Function(Map<String, dynamic>) map) {
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data.map((dynamic e) => map(e as Map<String, dynamic>)).toList();
  }

  Future<HealthProfile?> profile() async {
    final res = await _client.get<dynamic>('/nutrition/profile');
    final data = _itemOrNull(res);
    return data != null ? healthProfileFromJson(data) : null;
  }

  Future<HealthProfile> saveProfile(Map<String, dynamic> payload) async {
    final res = await _client.put<dynamic>('/nutrition/profile', data: payload);
    return healthProfileFromJson(_item(res));
  }

  Future<Assessment> assessment() async {
    final res = await _client.get<dynamic>('/nutrition/assessment');
    return assessmentFromJson(_item(res));
  }

  Future<DailySummary> diaryDay(String date) async {
    final res = await _client.get<dynamic>('/nutrition/diary', query: <String, dynamic>{'date': date});
    return dailySummaryFromJson(_item(res));
  }

  Future<List<MealPlanView>> mealPlans() async {
    final res = await _client.get<dynamic>('/nutrition/meal-plans');
    return _list(res, mealPlanFromJson);
  }

  Future<List<ProgressPoint>> progress() async {
    final res = await _client.get<dynamic>('/nutrition/progress');
    return _list(res, progressFromJson);
  }

  Future<ProgressPoint> recordProgress(Map<String, dynamic> payload) async {
    final res = await _client.post<dynamic>('/nutrition/progress', data: payload);
    return progressFromJson(_item(res));
  }

  Future<NutritionAdviceView> mealRecommendations() async {
    final res = await _client.get<dynamic>('/nutrition/recommendations/meals');
    return adviceFromJson(_item(res));
  }

  Future<NutritionAdviceView> weeklyInsights() async {
    final res = await _client.get<dynamic>('/nutrition/recommendations/weekly-insights');
    return adviceFromJson(_item(res));
  }
}
