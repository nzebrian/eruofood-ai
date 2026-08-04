import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/ai_entities.dart';

/// AI Engine repository contract (domain port).
abstract class AiRepository {
  Future<Either<Failure, AiRecipeResult>> generateRecipe({
    required String dishName,
    int servings,
    String? difficulty,
    List<String> dietary,
    List<String> ingredients,
  });

  Future<Either<Failure, ChatTurn>> chat(String message, {String? conversationId});

  Future<Either<Failure, List<ConversationSummary>>> conversations();

  Future<Either<Failure, Conversation>> conversation(String id);

  Future<Either<Failure, Unit>> deleteConversation(String id);

  Future<Either<Failure, UsageSummary>> usage({int days});
}
