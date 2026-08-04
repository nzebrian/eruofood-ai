import 'package:equatable/equatable.dart';

class RecipeSummary extends Equatable {
  const RecipeSummary({
    required this.id,
    required this.title,
    required this.slug,
    required this.difficulty,
    required this.totalTimeMinutes,
    required this.ratingAverage,
    required this.ratingCount,
    this.summary,
  });

  final String id;
  final String title;
  final String slug;
  final String difficulty;
  final int totalTimeMinutes;
  final double ratingAverage;
  final int ratingCount;
  final String? summary;

  @override
  List<Object?> get props =>
      <Object?>[id, title, slug, difficulty, totalTimeMinutes, ratingAverage, ratingCount, summary];
}

class RecipeIngredientLine extends Equatable {
  const RecipeIngredientLine({required this.name, required this.amount, required this.unit, this.note});

  final String name;
  final double amount;
  final String unit;
  final String? note;

  @override
  List<Object?> get props => <Object?>[name, amount, unit, note];
}

class RecipeStep extends Equatable {
  const RecipeStep({required this.order, required this.instruction, this.durationMinutes});

  final int order;
  final String instruction;
  final int? durationMinutes;

  @override
  List<Object?> get props => <Object?>[order, instruction, durationMinutes];
}

class Recipe extends RecipeSummary {
  const Recipe({
    required super.id,
    required super.title,
    required super.slug,
    required super.difficulty,
    required super.totalTimeMinutes,
    required super.ratingAverage,
    required super.ratingCount,
    required this.servingSize,
    required this.ingredients,
    required this.steps,
    super.summary,
    this.tips = const <String>[],
    this.isFavourited = false,
  });

  final int servingSize;
  final List<RecipeIngredientLine> ingredients;
  final List<RecipeStep> steps;
  final List<String> tips;
  final bool isFavourited;
}
