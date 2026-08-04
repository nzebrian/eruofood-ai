import '../../domain/entities/search_entities.dart';

SearchDocumentView documentFromJson(Map<String, dynamic> json) {
  return SearchDocumentView(
    id: json['id'] as String? ?? '',
    type: json['type'] as String? ?? '',
    title: json['title'] as String? ?? '',
    description: json['description'] as String? ?? '',
    rating: (json['rating'] as num?)?.toDouble() ?? 0,
    url: json['url'] as String?,
    image: json['image'] as String?,
    region: json['region'] as String?,
    priceMinor: (json['price_minor'] as num?)?.toInt(),
  );
}

SearchHitView hitFromJson(Map<String, dynamic> json) {
  return SearchHitView(
    document: documentFromJson(json['document'] as Map<String, dynamic>? ?? <String, dynamic>{}),
    score: (json['score'] as num?)?.toDouble() ?? 0,
    highlight: json['highlight'] as String?,
  );
}

SearchResultsView resultsFromJson(Map<String, dynamic> json) {
  final hits = (json['hits'] as List<dynamic>? ?? <dynamic>[])
      .map((dynamic e) => hitFromJson(e as Map<String, dynamic>))
      .toList();
  return SearchResultsView(
    queryId: json['query_id'] as String?,
    total: (json['total'] as num?)?.toInt() ?? 0,
    hits: hits,
  );
}
