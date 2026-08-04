import 'failure.dart';

/// Failure raised when the API returns an error or is unreachable.
class ServerFailure extends Failure {
  const ServerFailure(super.message);
}

/// Failure raised for authentication problems (bad credentials, expired token).
class AuthFailure extends Failure {
  const AuthFailure(super.message);
}
