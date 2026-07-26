import 'package:equatable/equatable.dart';

/// Computed nutrition assessment (BMI/BMR/TDEE + targets).
class Assessment extends Equatable {
  const Assessment({
    required this.bmi,
    required this.bmiCategory,
    required this.bmr,
    required this.tdee,
    required this.calorieTarget,
    required this.proteinGrams,
    required this.carbGrams,
    required this.fatGrams,
  });

  final double bmi;
  final String bmiCategory;
  final int bmr;
  final int tdee;
  final int calorieTarget;
  final int proteinGrams;
  final int carbGrams;
  final int fatGrams;

  @override
  List<Object?> get props =>
      <Object?>[bmi, bmiCategory, bmr, tdee, calorieTarget, proteinGrams, carbGrams, fatGrams];
}

/// A user's health profile (null fields become defaults in the form).
class HealthProfile extends Equatable {
  const HealthProfile({
    required this.weightKg,
    required this.heightCm,
    required this.age,
    required this.gender,
    required this.activityLevel,
    required this.goal,
    this.dietaryPreferences = const <String>[],
    this.allergies = const <String>[],
  });

  final double weightKg;
  final double heightCm;
  final int age;
  final String gender;
  final String activityLevel;
  final String goal;
  final List<String> dietaryPreferences;
  final List<String> allergies;

  @override
  List<Object?> get props =>
      <Object?>[weightKg, heightCm, age, gender, activityLevel, goal, dietaryPreferences, allergies];
}

/// A logged food shown in the daily summary.
class DiaryItem extends Equatable {
  const DiaryItem({required this.mealType, required this.itemName, required this.calories});

  final String mealType;
  final String itemName;
  final double calories;

  @override
  List<Object?> get props => <Object?>[mealType, itemName, calories];
}

/// A day's tracking totals.
class DailySummary extends Equatable {
  const DailySummary({
    required this.date,
    required this.totalCalories,
    required this.remainingCalories,
    required this.items,
  });

  final String date;
  final double totalCalories;
  final int? remainingCalories;
  final List<DiaryItem> items;

  @override
  List<Object?> get props => <Object?>[date, totalCalories, remainingCalories, items];
}

/// A meal plan summary.
class MealPlanView extends Equatable {
  const MealPlanView({
    required this.id,
    required this.title,
    required this.period,
    required this.mealCount,
    required this.estimatedCost,
  });

  final String id;
  final String title;
  final String period;
  final int mealCount;
  final double estimatedCost;

  @override
  List<Object?> get props => <Object?>[id, title, period, mealCount, estimatedCost];
}

/// A progress measurement.
class ProgressPoint extends Equatable {
  const ProgressPoint({required this.id, required this.date, required this.weightKg, this.note});

  final String id;
  final String date;
  final double weightKg;
  final String? note;

  @override
  List<Object?> get props => <Object?>[id, date, weightKg, note];
}

/// AI personalisation advice.
class NutritionAdviceView extends Equatable {
  const NutritionAdviceView({required this.advice, required this.provider});

  final String advice;
  final String provider;

  @override
  List<Object?> get props => <Object?>[advice, provider];
}
