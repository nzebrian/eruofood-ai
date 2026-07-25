import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/repositories/auth_repository.dart';
import '../../domain/usecases/auth_usecases.dart';
import 'auth_state.dart';

/// Drives authentication state for the UI. Delegates work to use cases and
/// translates results into AuthState transitions.
class AuthCubit extends Cubit<AuthState> {
  AuthCubit({
    required LoginUseCase login,
    required RegisterUseCase register,
    required LogoutUseCase logout,
    required GetCurrentUserUseCase currentUser,
    required AuthRepository repository,
  })  : _login = login,
        _register = register,
        _logout = logout,
        _currentUser = currentUser,
        _repository = repository,
        super(const AuthState());

  final LoginUseCase _login;
  final RegisterUseCase _register;
  final LogoutUseCase _logout;
  final GetCurrentUserUseCase _currentUser;
  final AuthRepository _repository;

  Future<void> bootstrap() async {
    if (!await _repository.hasSession()) {
      emit(state.copyWith(status: AuthStatus.unauthenticated));
      return;
    }
    final result = await _currentUser();
    result.fold(
      (failure) => emit(state.copyWith(status: AuthStatus.unauthenticated)),
      (user) => emit(state.copyWith(status: AuthStatus.authenticated, user: user)),
    );
  }

  Future<void> login({required String email, required String password}) async {
    emit(state.copyWith(status: AuthStatus.authenticating));
    final result = await _login(email: email, password: password);
    result.fold(
      (failure) => emit(state.copyWith(status: AuthStatus.unauthenticated, error: failure.message)),
      (user) => emit(state.copyWith(status: AuthStatus.authenticated, user: user)),
    );
  }

  Future<void> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    emit(state.copyWith(status: AuthStatus.authenticating));
    final result = await _register(
      name: name,
      email: email,
      password: password,
      passwordConfirmation: passwordConfirmation,
    );
    result.fold(
      (failure) => emit(state.copyWith(status: AuthStatus.unauthenticated, error: failure.message)),
      (user) => emit(state.copyWith(status: AuthStatus.authenticated, user: user)),
    );
  }

  Future<void> logout() async {
    await _logout();
    emit(const AuthState(status: AuthStatus.unauthenticated));
  }
}
