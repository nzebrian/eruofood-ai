import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../../../core/storage/token_store.dart';
import '../../domain/entities/auth_user.dart';
import '../../domain/repositories/auth_repository.dart';
import '../datasources/auth_remote_data_source.dart';

/// Implements the AuthRepository port: calls the remote data source, persists
/// tokens, and maps Dio errors to domain Failures.
class AuthRepositoryImpl implements AuthRepository {
  AuthRepositoryImpl(this._remote, this._tokens);

  final AuthRemoteDataSource _remote;
  final TokenStore _tokens;

  @override
  Future<Either<Failure, AuthUser>> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) {
    return _guard(() async {
      final result = await _remote.register(
        name: name,
        email: email,
        password: password,
        passwordConfirmation: passwordConfirmation,
      );
      await _tokens.save(accessToken: result.accessToken, refreshToken: result.refreshToken);
      return result.user;
    });
  }

  @override
  Future<Either<Failure, AuthUser>> login({required String email, required String password}) {
    return _guard(() async {
      final result = await _remote.login(email: email, password: password);
      await _tokens.save(accessToken: result.accessToken, refreshToken: result.refreshToken);
      return result.user;
    });
  }

  @override
  Future<Either<Failure, AuthUser>> currentUser() {
    return _guard(() => _remote.me());
  }

  @override
  Future<Either<Failure, Unit>> logout() async {
    final String? refresh = await _tokens.refreshToken();
    try {
      if (refresh != null) {
        await _remote.logout(refresh);
      }
    } on DioException {
      // Ignore network errors on logout; clearing local tokens is enough.
    }
    await _tokens.clear();
    return const Right<Failure, Unit>(unit);
  }

  @override
  Future<bool> hasSession() async => (await _tokens.accessToken()) != null;

  /// Runs a remote call and converts Dio errors into typed Failures.
  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      final data = e.response?.data;
      final message = data is Map<String, dynamic> && data['error'] is Map<String, dynamic>
          ? (data['error'] as Map<String, dynamic>)['message'] as String? ?? 'Request failed.'
          : 'Network error. Please try again.';
      if (e.response?.statusCode == 401) {
        return Left<Failure, T>(AuthFailure(message));
      }
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
