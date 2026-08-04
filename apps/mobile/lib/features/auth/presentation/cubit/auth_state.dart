import 'package:equatable/equatable.dart';

import '../../domain/entities/auth_user.dart';

enum AuthStatus { unknown, authenticating, authenticated, unauthenticated }

class AuthState extends Equatable {
  const AuthState({this.status = AuthStatus.unknown, this.user, this.error});

  final AuthStatus status;
  final AuthUser? user;
  final String? error;

  AuthState copyWith({AuthStatus? status, AuthUser? user, String? error}) {
    return AuthState(
      status: status ?? this.status,
      user: user ?? this.user,
      error: error,
    );
  }

  @override
  List<Object?> get props => <Object?>[status, user, error];
}
