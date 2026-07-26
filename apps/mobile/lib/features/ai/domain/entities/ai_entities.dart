import 'package:equatable/equatable.dart';

/// Provenance metadata attached to every AI result.
class AiMeta extends Equatable {
  const AiMeta({
    required this.provider,
    required this.model,
    required this.cached,
    required this.totalTokens,
  });

  final String provider;
  final String model;
  final bool cached;
  final int totalTokens;

  @override
  List<Object?> get props => <Object?>[provider, model, cached, totalTokens];
}

/// A generated recipe (loosely structured — fields are best-effort).
class GeneratedRecipe extends Equatable {
  const GeneratedRecipe({
    this.title,
    this.summary,
    this.ingredients = const <String>[],
    this.steps = const <String>[],
    this.tips = const <String>[],
  });

  final String? title;
  final String? summary;
  final List<String> ingredients;
  final List<String> steps;
  final List<String> tips;

  @override
  List<Object?> get props => <Object?>[title, summary, ingredients, steps, tips];
}

/// A recipe generation outcome plus its metadata.
class AiRecipeResult extends Equatable {
  const AiRecipeResult({required this.recipe, required this.meta});

  final GeneratedRecipe recipe;
  final AiMeta meta;

  @override
  List<Object?> get props => <Object?>[recipe, meta];
}

/// A single chat turn.
class ChatMessage extends Equatable {
  const ChatMessage({required this.role, required this.content});

  final String role; // user | assistant
  final String content;

  bool get isUser => role == 'user';

  @override
  List<Object?> get props => <Object?>[role, content];
}

/// The result of one assistant exchange.
class ChatTurn extends Equatable {
  const ChatTurn({required this.conversationId, required this.reply, required this.meta});

  final String conversationId;
  final String reply;
  final AiMeta meta;

  @override
  List<Object?> get props => <Object?>[conversationId, reply, meta];
}

/// A conversation in the history list.
class ConversationSummary extends Equatable {
  const ConversationSummary({
    required this.id,
    required this.title,
    required this.messageCount,
  });

  final String id;
  final String title;
  final int messageCount;

  @override
  List<Object?> get props => <Object?>[id, title, messageCount];
}

/// A full conversation thread.
class Conversation extends ConversationSummary {
  const Conversation({
    required super.id,
    required super.title,
    required super.messageCount,
    required this.messages,
  });

  final List<ChatMessage> messages;

  @override
  List<Object?> get props => <Object?>[id, title, messageCount, messages];
}

/// Rolling AI usage & cost totals.
class UsageSummary extends Equatable {
  const UsageSummary({
    required this.requests,
    required this.cachedRequests,
    required this.totalTokens,
    required this.costUsd,
  });

  final int requests;
  final int cachedRequests;
  final int totalTokens;
  final double costUsd;

  @override
  List<Object?> get props => <Object?>[requests, cachedRequests, totalTokens, costUsd];
}
