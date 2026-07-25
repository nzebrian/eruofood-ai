import 'package:dio/dio.dart';

import '../config/app_config.dart';

/// Thin wrapper around Dio that standardises the base URL, headers, and
/// timeouts for all REST calls. Feature data sources depend on this; no
/// business endpoints are defined in the foundation.
class ApiClient {
  ApiClient(AppConfig config)
      : _dio = Dio(
          BaseOptions(
            baseUrl: config.apiBaseUrl,
            connectTimeout: const Duration(seconds: 10),
            receiveTimeout: const Duration(seconds: 15),
            headers: <String, String>{
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
          ),
        );

  final Dio _dio;

  Dio get raw => _dio;

  Future<Response<T>> get<T>(String path, {Map<String, dynamic>? query}) {
    return _dio.get<T>(path, queryParameters: query);
  }

  Future<Response<T>> post<T>(String path, {Object? data}) {
    return _dio.post<T>(path, data: data);
  }
}
