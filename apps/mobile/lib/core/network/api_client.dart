import 'package:dio/dio.dart';

import '../config/app_config.dart';

/// Thin wrapper around Dio that standardises the base URL, headers, timeouts,
/// and bearer-token injection for all REST calls. Feature data sources depend
/// on this; no business endpoints are defined in the foundation.
class ApiClient {
  ApiClient(AppConfig config, {Future<String?> Function()? tokenProvider})
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
        ) {
    if (tokenProvider != null) {
      _dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (RequestOptions options, RequestInterceptorHandler handler) async {
            final String? token = await tokenProvider();
            if (token != null) {
              options.headers['Authorization'] = 'Bearer $token';
            }
            handler.next(options);
          },
        ),
      );
    }
  }

  final Dio _dio;

  Dio get raw => _dio;

  Future<Response<T>> get<T>(String path, {Map<String, dynamic>? query}) {
    return _dio.get<T>(path, queryParameters: query);
  }

  Future<Response<T>> post<T>(String path, {Object? data}) {
    return _dio.post<T>(path, data: data);
  }

  Future<Response<T>> put<T>(String path, {Object? data}) {
    return _dio.put<T>(path, data: data);
  }
}
