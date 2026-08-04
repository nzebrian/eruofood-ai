import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/ai_entities.dart';
import '../../domain/repositories/ai_repository.dart';
import '../datasources/ai_remote_data_source.dart';

class AiRepositoryImpl implements AiRepository {
  AiRepositoryImpl(this._remote);

  final AiRemoteDataSource _remote;

  @override
  Future<Either<Failure, AiRecipeResult>> generateRecipe({
    required String dishName,
    int servings = 4,
    String? difficulty,
    List<String> dietary = const <String>[],
    List<String> ingredients = const <String>[],
  }) =>
      _guard(() => _remote.generateRecipe(
            dishName: dishName,
            servings: servings,
            difficulty: difficulty,
            dietary: dietary,
            ingredients: ingredients,
          ));

  @override
  Future<Either<Failure, ChatTurn>> chat(String message, {String? conversationId}) =>
      _guard(() => _remote.chat(message, conversationId: conversationId));

  @override
  Future<Either<Failure, List<ConversationSummary>>> conversations() =>
      _guard(() => _remote.conversations());

  @override
  Future<Either<Failure, Conversation>> conversation(String id) =>
      _guard(() => _remote.conversation(id));

  @override
  Future<Either<Failure, Unit>> deleteConversation(String id) => _guard(() async {
        await _remote.deleteConversation(id);
        return unit;
      });

  @override
  Future<Either<Failure, UsageSummary>> usage({int days = 30}) =>
      _guard(() => _remote.usage(days: days));

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      return Left<Failure, T>(ServerFailure(e.message ?? 'Network error.'));
    }
  }
}
