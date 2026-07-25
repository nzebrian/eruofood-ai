import 'package:dio/dio.dart';

import '../../../../core/network/api_client.dart';
import '../models/auth_user_model.dart';

/// Result of an authentication call: the user plus the issued tokens.
class AuthResult {
  const AuthResult({required this.user, required this.accessToken, required this.refreshToken});

  final AuthUserModel user;
  final String accessToken;
  final String refreshToken;

  factory AuthResult.fromData(Map<String, dynamic> data) {
    final tokens = data['tokens'] as Map<String, dynamic>;
    return AuthResult(
      user: AuthUserModel.fromJson(data['user'] as Map<String, dynamic>),
      accessToken: tokens['access_token'] as String,
      refreshToken: tokens['refresh_token'] as String,
    );
  }
}

/// Talks to the Identity REST endpoints over the shared ApiClient.
class AuthRemoteDataSource {
  AuthRemoteDataSource(this._client);

  final ApiClient _client;

  Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final Response<dynamic> res = await _client.post<dynamic>('/auth/register', data: <String, dynamic>{
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    return AuthResult.fromData((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<AuthResult> login({required String email, required String password}) async {
    final Response<dynamic> res = await _client.post<dynamic>('/auth/login', data: <String, dynamic>{
      'email': email,
      'password': password,
    });
    return AuthResult.fromData((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<AuthUserModel> me() async {
    final Response<dynamic> res = await _client.get<dynamic>('/me');
    return AuthUserModel.fromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<void> logout(String refreshToken) async {
    await _client.post<dynamic>('/auth/logout', data: <String, dynamic>{'refresh_token': refreshToken});
  }
}
