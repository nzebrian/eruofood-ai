import '../../../../core/network/api_client.dart';
import '../../domain/entities/search_entities.dart';
import '../models/search_models.dart';

/// Reads the Search REST endpoints (mounted at /search).
class SearchRemoteDataSource {
  SearchRemoteDataSource(this._client);

  final ApiClient _client;

  Future<SearchResultsView> search(
    String term,
    String type,
    String sort,
    SearchFiltersView filters,
  ) async {
    final query = <String, dynamic>{'q': term, 'type': type, 'sort': sort, ...filters.toQuery()};
    final res = await _client.get<dynamic>('/search', query: query);
    final data = (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return resultsFromJson(data);
  }

  Future<List<String>> autocomplete(String term, String type) async {
    final res = await _client.get<dynamic>('/search/autocomplete', query: <String, dynamic>{'q': term, 'type': type});
    final data = (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return ((data['suggestions'] as List<dynamic>?) ?? <dynamic>[]).map((dynamic e) => e.toString()).toList();
  }

  Future<List<SearchDocumentView>> recommendations(String kind, String type) async {
    final res = await _client.get<dynamic>('/search/recommendations', query: <String, dynamic>{'kind': kind, 'type': type});
    final data = (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return ((data['items'] as List<dynamic>?) ?? <dynamic>[])
        .map((dynamic e) => documentFromJson(e as Map<String, dynamic>))
        .toList();
  }
}
