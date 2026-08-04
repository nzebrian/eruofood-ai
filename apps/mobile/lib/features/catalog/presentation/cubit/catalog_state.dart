import 'package:equatable/equatable.dart';

import '../../domain/entities/food.dart';

enum CatalogStatus { initial, loading, loaded, error }

class CatalogState extends Equatable {
  const CatalogState({
    this.status = CatalogStatus.initial,
    this.foods = const <FoodSummary>[],
    this.query = '',
    this.region,
    this.error,
  });

  final CatalogStatus status;
  final List<FoodSummary> foods;
  final String query;
  final String? region;
  final String? error;

  CatalogState copyWith({
    CatalogStatus? status,
    List<FoodSummary>? foods,
    String? query,
    String? region,
    String? error,
  }) {
    return CatalogState(
      status: status ?? this.status,
      foods: foods ?? this.foods,
      query: query ?? this.query,
      region: region ?? this.region,
      error: error,
    );
  }

  @override
  List<Object?> get props => <Object?>[status, foods, query, region, error];
}
