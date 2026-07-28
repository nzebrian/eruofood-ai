import 'package:equatable/equatable.dart';

/// A single indexed item returned by search or recommendations.
class SearchDocumentView extends Equatable {
  const SearchDocumentView({
    required this.id,
    required this.type,
    required this.title,
    required this.description,
    required this.rating,
    this.url,
    this.image,
    this.region,
    this.priceMinor,
  });

  final String id;
  final String type;
  final String title;
  final String description;
  final double rating;
  final String? url;
  final String? image;
  final String? region;
  final int? priceMinor;

  @override
  List<Object?> get props =>
      <Object?>[id, type, title, description, rating, url, image, region, priceMinor];
}

/// A ranked search hit.
class SearchHitView extends Equatable {
  const SearchHitView({required this.document, required this.score, this.highlight});

  final SearchDocumentView document;
  final double score;
  final String? highlight;

  @override
  List<Object?> get props => <Object?>[document, score, highlight];
}

/// A page of ranked results.
class SearchResultsView extends Equatable {
  const SearchResultsView({
    required this.queryId,
    required this.total,
    required this.hits,
  });

  final String? queryId;
  final int total;
  final List<SearchHitView> hits;

  @override
  List<Object?> get props => <Object?>[queryId, total, hits];
}

/// Optional discovery filters.
class SearchFiltersView extends Equatable {
  const SearchFiltersView({this.region, this.difficulty, this.minRating, this.maxCookingTime});

  final String? region;
  final String? difficulty;
  final double? minRating;
  final int? maxCookingTime;

  Map<String, dynamic> toQuery() {
    final map = <String, dynamic>{};
    if (region != null && region!.isNotEmpty) map['region'] = region;
    if (difficulty != null && difficulty!.isNotEmpty) map['difficulty'] = difficulty;
    if (minRating != null) map['min_rating'] = minRating;
    if (maxCookingTime != null) map['max_cooking_time'] = maxCookingTime;
    return map;
  }

  @override
  List<Object?> get props => <Object?>[region, difficulty, minRating, maxCookingTime];
}
