import 'package:eruofood/features/catalog/data/models/food_model.dart';
import 'package:eruofood/features/catalog/data/models/recipe_model.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('FoodModel maps summary + detail fields', () {
    final food = FoodModel.fromJson(<String, dynamic>{
      'id': 'f1',
      'name': 'Jollof Rice',
      'slug': 'jollof-rice',
      'region': 'south_west',
      'tags': <dynamic>['party'],
      'primary_image': null,
      'description': 'Smoky party rice.',
      'states': <dynamic>['Lagos'],
      'local_names': <dynamic>[
        <String, dynamic>{'name': 'Jollof', 'language': 'pidgin'},
      ],
      'images': <dynamic>[],
    });

    expect(food.name, 'Jollof Rice');
    expect(food.states, <String>['Lagos']);
    expect(food.localNames.first.language, 'pidgin');
  });

  test('RecipeModel maps ingredients and steps', () {
    final recipe = RecipeModel.fromJson(<String, dynamic>{
      'id': 'r1',
      'title': 'Classic Jollof',
      'slug': 'classic-jollof',
      'difficulty': 'medium',
      'total_time_minutes': 65,
      'rating_average': 4.5,
      'rating_count': 10,
      'serving_size': 6,
      'ingredients': <dynamic>[
        <String, dynamic>{'name': 'Rice', 'amount': 4, 'unit': 'cup', 'note': null},
      ],
      'steps': <dynamic>[
        <String, dynamic>{'order': 1, 'instruction': 'Cook', 'duration_minutes': 25},
      ],
      'tips': <dynamic>['Low heat'],
      'is_favourited': true,
    });

    expect(recipe.servingSize, 6);
    expect(recipe.ingredients.first.unit, 'cup');
    expect(recipe.steps.first.order, 1);
    expect(recipe.isFavourited, isTrue);
  });
}
