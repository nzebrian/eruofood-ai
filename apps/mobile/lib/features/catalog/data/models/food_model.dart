import '../../domain/entities/food.dart';

class FoodSummaryModel extends FoodSummary {
  const FoodSummaryModel({
    required super.id,
    required super.name,
    required super.slug,
    required super.region,
    required super.tags,
    super.primaryImage,
  });

  factory FoodSummaryModel.fromJson(Map<String, dynamic> json) {
    return FoodSummaryModel(
      id: json['id'] as String,
      name: json['name'] as String,
      slug: json['slug'] as String,
      region: json['region'] as String? ?? 'nationwide',
      tags: ((json['tags'] as List<dynamic>?) ?? <dynamic>[]).map((dynamic t) => t as String).toList(),
      primaryImage: json['primary_image'] as String?,
    );
  }
}

class FoodModel extends Food {
  const FoodModel({
    required super.id,
    required super.name,
    required super.slug,
    required super.region,
    required super.tags,
    super.primaryImage,
    super.description,
    super.states,
    super.localNames,
    super.images,
  });

  factory FoodModel.fromJson(Map<String, dynamic> json) {
    return FoodModel(
      id: json['id'] as String,
      name: json['name'] as String,
      slug: json['slug'] as String,
      region: json['region'] as String? ?? 'nationwide',
      tags: ((json['tags'] as List<dynamic>?) ?? <dynamic>[]).map((dynamic t) => t as String).toList(),
      primaryImage: json['primary_image'] as String?,
      description: json['description'] as String?,
      states: ((json['states'] as List<dynamic>?) ?? <dynamic>[]).map((dynamic s) => s as String).toList(),
      localNames: ((json['local_names'] as List<dynamic>?) ?? <dynamic>[])
          .map((dynamic l) => LocalName(
                name: (l as Map<String, dynamic>)['name'] as String,
                language: l['language'] as String,
              ))
          .toList(),
      images: ((json['images'] as List<dynamic>?) ?? <dynamic>[]).map((dynamic i) => i as String).toList(),
    );
  }
}
