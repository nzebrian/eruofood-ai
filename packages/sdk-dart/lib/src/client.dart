import 'dart:convert';

import 'package:http/http.dart' as http;

import 'exceptions.dart';
import 'page.dart';

/// Minimal client for the EruoFood Public API. Auth is via API key (Bearer).
class EruoFoodClient {
  EruoFoodClient({
    required this.apiKey,
    this.baseUrl = 'https://api.eruofood.example/api/public/v1',
    http.Client? httpClient,
    this.timeout = const Duration(seconds: 10),
  })  : _http = httpClient ?? http.Client(),
        assert(apiKey != '', 'An API key is required.');

  final String apiKey;
  final String baseUrl;
  final Duration timeout;
  final http.Client _http;

  /// GET a single resource; returns the unwrapped `data`.
  Future<Map<String, dynamic>> get(String path, [Map<String, dynamic>? query]) async {
    final body = await _request(path, query);
    return (body['data'] as Map<String, dynamic>?) ?? <String, dynamic>{};
  }

  /// GET a paginated collection.
  Future<Page<Map<String, dynamic>>> getPage(String path, [Map<String, dynamic>? query]) async {
    final body = await _request(path, query);
    final meta = (body['meta'] as Map<String, dynamic>? ?? <String, dynamic>{});
    return Page<Map<String, dynamic>>(
      data: (body['data'] as List<dynamic>? ?? <dynamic>[]).cast<Map<String, dynamic>>(),
      pagination: Pagination.fromJson(meta['pagination'] as Map<String, dynamic>? ?? <String, dynamic>{}),
      version: meta['version'] as String? ?? 'v1',
    );
  }

  /// Fetch every item across all pages.
  Stream<Map<String, dynamic>> paginate(String path, [Map<String, dynamic>? query]) async* {
    var page = (query?['page'] as int?) ?? 1;
    while (true) {
      final result = await getPage(path, {...?query, 'page': page});
      for (final item in result.data) {
        yield item;
      }
      if (!result.pagination.hasMore) return;
      page += 1;
    }
  }

  /// POST a resource; returns the unwrapped `data`.
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) async {
    final result = await _request(path, null, method: 'POST', jsonBody: body);
    return (result['data'] as Map<String, dynamic>?) ?? <String, dynamic>{};
  }

  // --- Convenience resource methods (thin wrappers) ---
  Future<Page<Map<String, dynamic>>> restaurants([Map<String, dynamic>? query]) => getPage('/restaurants', query);
  Future<Map<String, dynamic>> restaurant(String slug) => get('/restaurants/${Uri.encodeComponent(slug)}');
  Future<Map<String, dynamic>> restaurantMenu(String id) => get('/restaurants/${Uri.encodeComponent(id)}/menu');

  Future<Page<Map<String, dynamic>>> products([Map<String, dynamic>? query]) => getPage('/products', query);
  Future<Map<String, dynamic>> product(String slug) => get('/products/${Uri.encodeComponent(slug)}');
  Future<Map<String, dynamic>> productCategories() => get('/product-categories');

  Future<Page<Map<String, dynamic>>> nutritionItems([Map<String, dynamic>? query]) => getPage('/nutrition', query);
  Future<Map<String, dynamic>> nutritionItem(String id) => get('/nutrition/${Uri.encodeComponent(id)}');

  Future<Map<String, dynamic>> search([Map<String, dynamic>? query]) => get('/search', query);

  // Orders — customer-scoped, BOLA-enforced.
  Future<Page<Map<String, dynamic>>> orders([Map<String, dynamic>? query]) => getPage('/orders', query);
  Future<Map<String, dynamic>> order(String id) => get('/orders/${Uri.encodeComponent(id)}');
  Future<Map<String, dynamic>> createOrder([Map<String, dynamic>? body]) => post('/orders', body);
  Future<Map<String, dynamic>> cancelOrder(String id) => post('/orders/${Uri.encodeComponent(id)}/cancel');

  Future<Map<String, dynamic>> _request(
    String path,
    Map<String, dynamic>? query, {
    String method = 'GET',
    Map<String, dynamic>? jsonBody,
  }) async {
    final uri = Uri.parse('$baseUrl$path').replace(
      queryParameters: query?.map((k, v) => MapEntry(k, '$v')),
    );
    final headers = {
      'Authorization': 'Bearer $apiKey',
      'Accept': 'application/json',
    };
    final http.Response res;
    if (method == 'POST') {
      headers['Content-Type'] = 'application/json';
      res = await _http.post(uri, headers: headers, body: jsonEncode(jsonBody ?? <String, dynamic>{})).timeout(timeout);
    } else {
      res = await _http.get(uri, headers: headers).timeout(timeout);
    }

    final body = res.body.isNotEmpty ? jsonDecode(res.body) as Map<String, dynamic> : <String, dynamic>{};
    if (res.statusCode < 200 || res.statusCode >= 300) {
      final err = body['error'] as Map<String, dynamic>? ?? <String, dynamic>{};
      throw EruoFoodApiException(
        res.statusCode,
        err['code'] as String? ?? 'error',
        err['message'] as String? ?? 'Request failed',
        err['details'],
      );
    }
    return body;
  }
}
