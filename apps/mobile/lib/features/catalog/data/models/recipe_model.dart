import '../../domain/entities/recipe.dart';

class RecipeSummaryModel extends RecipeSummary {
  const RecipeSummaryModel({
    required super.id,
    required super.title,
    required super.slug,
    required super.difficulty,
    required super.totalTimeMinutes,
    required super.ratingAverage,
    required super.ratingCount,
    super.summary,
  });

  factory RecipeSummaryModel.fromJson(Map<String, dynamic> json) {
    return RecipeSummaryModel(
      id: json['id'] as String,
      title: json['title'] as String,
      slug: json['slug'] as String,
      difficulty: json['difficulty'] as String? ?? 'easy',
      totalTimeMinutes: (json['total_time_minutes'] as num?)?.toInt() ?? 0,
      ratingAverage: (json['rating_average'] as num?)?.toDouble() ?? 0,
      ratingCount: (json['rating_count'] as num?)?.toInt() ?? 0,
      summary: json['summary'] as String?,
    );
  }
}

class RecipeModel extends Recipe {
  const RecipeModel({
    required super.id,
    required super.title,
    required super.slug,
    required super.difficulty,
    required super.totalTimeMinutes,
    required super.ratingAverage,
    required super.ratingCount,
    required super.servingSize,
    required super.ingredients,
    required super.steps,
    super.summary,
    super.tips,
    super.isFavourited,
  });

  factory RecipeModel.fromJson(Map<String, dynamic> json) {
    return RecipeModel(
      id: json['id'] as String,
      title: json['title'] as String,
      slug: json['slug'] as String,
      difficulty: json['difficulty'] as String? ?? 'easy',
      totalTimeMinutes: (json['total_time_minutes'] as num?)?.toInt() ?? 0,
      ratingAverage: (json['rating_average'] as num?)?.toDouble() ?? 0,
      ratingCount: (json['rating_count'] as num?)?.toInt() ?? 0,
      servingSize: (json['serving_size'] as num?)?.toInt() ?? 1,
      summary: json['summary'] as String?,
      isFavourited: json['is_favourited'] as bool? ?? false,
      ingredients: ((json['ingredients'] as List<dynamic>?) ?? <dynamic>[])
          .map((dynamic i) => RecipeIngredientLine(
                name: (i as Map<String, dynamic>)['name'] as String,
                amount: (i['amount'] as num).toDouble(),
                unit: i['unit'] as String,
                note: i['note'] as String?,
              ))
          .toList(),
      steps: ((json['steps'] as List<dynamic>?) ?? <dynamic>[])
          .map((dynamic s) => RecipeStep(
                order: (s as Map<String, dynamic>)['order'] as int,
                instruction: s['instruction'] as String,
                durationMinutes: s['duration_minutes'] as int?,
              ))
          .toList(),
      tips: ((json['tips'] as List<dynamic>?) ?? <dynamic>[]).map((dynamic t) => t as String).toList(),
    );
  }
}
