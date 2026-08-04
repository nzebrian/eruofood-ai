import 'package:equatable/equatable.dart';

/// Base type for domain failures returned from the data/application layers
/// (used with `Either<Failure, T>` from dartz). Feature-specific failures
/// extend this. No business failures are defined in the foundation.
abstract class Failure extends Equatable {
  const Failure(this.message);

  final String message;

  @override
  List<Object?> get props => <Object?>[message];
}
