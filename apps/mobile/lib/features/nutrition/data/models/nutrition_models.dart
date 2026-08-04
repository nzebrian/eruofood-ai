import '../../domain/entities/nutrition_entities.dart';

/// JSON mappers for the Nutrition API payloads.
List<String> _strings(dynamic value) =>
    value is List ? value.map((dynamic e) => e.toString()).toList() : const <String>[];

Assessment assessmentFromJson(Map<String, dynamic> json) {
  final macros = (json['macro_targets'] as Map<String, dynamic>?) ?? <String, dynamic>{};
  return Assessment(
    bmi: (json['bmi'] as num?)?.toDouble() ?? 0,
    bmiCategory: json['bmi_category'] as String? ?? 'unknown',
    bmr: (json['bmr'] as num?)?.toInt() ?? 0,
    tdee: (json['tdee'] as num?)?.toInt() ?? 0,
    calorieTarget: (json['calorie_target'] as num?)?.toInt() ?? 0,
    proteinGrams: (macros['protein_grams'] as num?)?.toInt() ?? 0,
    carbGrams: (macros['carb_grams'] as num?)?.toInt() ?? 0,
    fatGrams: (macros['fat_grams'] as num?)?.toInt() ?? 0,
  );
}

HealthProfile healthProfileFromJson(Map<String, dynamic> json) {
  return HealthProfile(
    weightKg: (json['weight_kg'] as num?)?.toDouble() ?? 70,
    heightCm: (json['height_cm'] as num?)?.toDouble() ?? 170,
    age: (json['age'] as num?)?.toInt() ?? 30,
    gender: json['gender'] as String? ?? 'male',
    activityLevel: json['activity_level'] as String? ?? 'moderate',
    goal: json['goal'] as String? ?? 'maintain',
    dietaryPreferences: _strings(json['dietary_preferences']),
    allergies: _strings(json['allergies']),
  );
}

DailySummary dailySummaryFromJson(Map<String, dynamic> json) {
  final totals = (json['totals'] as Map<String, dynamic>?) ?? <String, dynamic>{};
  final entries = (json['entries'] as List<dynamic>?) ?? <dynamic>[];
  return DailySummary(
    date: json['date'] as String? ?? '',
    totalCalories: (totals['calories'] as num?)?.toDouble() ?? 0,
    remainingCalories: (json['remaining_calories'] as num?)?.toInt(),
    items: entries.map((dynamic e) {
      final row = e as Map<String, dynamic>;
      final nutrition = (row['nutrition'] as Map<String, dynamic>?) ?? <String, dynamic>{};
      return DiaryItem(
        mealType: row['meal_type'] as String? ?? '',
        itemName: row['item_name'] as String? ?? '',
        calories: (nutrition['calories'] as num?)?.toDouble() ?? 0,
      );
    }).toList(),
  );
}

MealPlanView mealPlanFromJson(Map<String, dynamic> json) {
  final entries = (json['entries'] as List<dynamic>?) ?? <dynamic>[];
  return MealPlanView(
    id: json['id'] as String,
    title: json['title'] as String? ?? 'Plan',
    period: json['period'] as String? ?? 'weekly',
    mealCount: entries.length,
    estimatedCost: (json['estimated_cost'] as num?)?.toDouble() ?? 0,
  );
}

ProgressPoint progressFromJson(Map<String, dynamic> json) {
  return ProgressPoint(
    id: json['id'] as String,
    date: json['date'] as String? ?? '',
    weightKg: (json['weight_kg'] as num?)?.toDouble() ?? 0,
    note: json['note'] as String?,
  );
}

NutritionAdviceView adviceFromJson(Map<String, dynamic> json) {
  final meta = (json['meta'] as Map<String, dynamic>?) ?? <String, dynamic>{};
  return NutritionAdviceView(
    advice: json['advice'] as String? ?? '',
    provider: meta['provider'] as String? ?? 'unknown',
  );
}
