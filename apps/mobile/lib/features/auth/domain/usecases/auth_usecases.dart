import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/auth_user.dart';
import '../repositories/auth_repository.dart';

/// Thin use-case objects wrapping single repository operations. They keep the
/// presentation layer decoupled from the repository and give each business
/// action a named, testable entry point (Single Responsibility Principle).

class LoginUseCase {
  const LoginUseCase(this._repository);
  final AuthRepository _repository;

  Future<Either<Failure, AuthUser>> call({required String email, required String password}) {
    return _repository.login(email: email, password: password);
  }
}

class RegisterUseCase {
  const RegisterUseCase(this._repository);
  final AuthRepository _repository;

  Future<Either<Failure, AuthUser>> call({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) {
    return _repository.register(
      name: name,
      email: email,
      password: password,
      passwordConfirmation: passwordConfirmation,
    );
  }
}

class GetCurrentUserUseCase {
  const GetCurrentUserUseCase(this._repository);
  final AuthRepository _repository;

  Future<Either<Failure, AuthUser>> call() => _repository.currentUser();
}

class LogoutUseCase {
  const LogoutUseCase(this._repository);
  final AuthRepository _repository;

  Future<Either<Failure, Unit>> call() => _repository.logout();
}
