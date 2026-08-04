import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/search_entities.dart';

/// Contract for the Search, Discovery & Recommendation feature.
abstract class SearchRepository {
  Future<Either<Failure, SearchResultsView>> search(
    String term,
    String type,
    String sort,
    SearchFiltersView filters,
  );

  Future<Either<Failure, List<String>>> autocomplete(String term, String type);

  Future<Either<Failure, List<SearchDocumentView>>> recommendations(String kind, String type);
}
