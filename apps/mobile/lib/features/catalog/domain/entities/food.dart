import 'package:equatable/equatable.dart';

class FoodSummary extends Equatable {
  const FoodSummary({
    required this.id,
    required this.name,
    required this.slug,
    required this.region,
    required this.tags,
    this.primaryImage,
  });

  final String id;
  final String name;
  final String slug;
  final String region;
  final List<String> tags;
  final String? primaryImage;

  @override
  List<Object?> get props => <Object?>[id, name, slug, region, tags, primaryImage];
}

class LocalName extends Equatable {
  const LocalName({required this.name, required this.language});

  final String name;
  final String language;

  @override
  List<Object?> get props => <Object?>[name, language];
}

class Food extends FoodSummary {
  const Food({
    required super.id,
    required super.name,
    required super.slug,
    required super.region,
    required super.tags,
    super.primaryImage,
    this.description,
    this.states = const <String>[],
    this.localNames = const <LocalName>[],
    this.images = const <String>[],
  });

  final String? description;
  final List<String> states;
  final List<LocalName> localNames;
  final List<String> images;
}
