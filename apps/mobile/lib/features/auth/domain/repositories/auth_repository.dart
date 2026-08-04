import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/auth_user.dart';

/// Auth repository contract (domain port). The data layer implements it; the
/// presentation layer depends only on this abstraction.
abstract class AuthRepository {
  Future<Either<Failure, AuthUser>> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  });

  Future<Either<Failure, AuthUser>> login({
    required String email,
    required String password,
  });

  Future<Either<Failure, AuthUser>> currentUser();

  Future<Either<Failure, Unit>> logout();

  Future<bool> hasSession();
}
