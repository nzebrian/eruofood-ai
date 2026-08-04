import '../../domain/entities/ai_entities.dart';

/// JSON mappers for the AI API payloads. Kept in the data layer so the domain
/// entities stay free of serialisation concerns.
List<String> _stringList(dynamic value) {
  if (value is List) {
    return value.map((dynamic e) => e.toString()).toList();
  }
  return const <String>[];
}

AiMeta aiMetaFromJson(Map<String, dynamic> json) {
  final tokens = (json['tokens'] as Map<String, dynamic>?) ?? <String, dynamic>{};
  return AiMeta(
    provider: json['provider'] as String? ?? 'unknown',
    model: json['model'] as String? ?? 'unknown',
    cached: json['cached'] as bool? ?? false,
    totalTokens: (tokens['total'] as num?)?.toInt() ?? 0,
  );
}

AiRecipeResult aiRecipeResultFromJson(Map<String, dynamic> json) {
  final content = json['content'];
  final recipe = content is Map<String, dynamic>
      ? GeneratedRecipe(
          title: content['title'] as String?,
          summary: content['summary'] as String?,
          ingredients: _stringList(content['ingredients']),
          steps: _stringList(content['steps']),
          tips: _stringList(content['tips']),
        )
      : const GeneratedRecipe();

  return AiRecipeResult(
    recipe: recipe,
    meta: aiMetaFromJson((json['meta'] as Map<String, dynamic>?) ?? <String, dynamic>{}),
  );
}

ChatTurn chatTurnFromJson(Map<String, dynamic> json) {
  return ChatTurn(
    conversationId: json['conversation_id'] as String,
    reply: json['reply'] as String? ?? '',
    meta: aiMetaFromJson((json['meta'] as Map<String, dynamic>?) ?? <String, dynamic>{}),
  );
}

ChatMessage chatMessageFromJson(Map<String, dynamic> json) {
  return ChatMessage(
    role: json['role'] as String? ?? 'assistant',
    content: json['content'] as String? ?? '',
  );
}

ConversationSummary conversationSummaryFromJson(Map<String, dynamic> json) {
  return ConversationSummary(
    id: json['id'] as String,
    title: json['title'] as String? ?? 'Conversation',
    messageCount: (json['message_count'] as num?)?.toInt() ?? 0,
  );
}

Conversation conversationFromJson(Map<String, dynamic> json) {
  final messages = (json['messages'] as List<dynamic>?) ?? <dynamic>[];
  return Conversation(
    id: json['id'] as String,
    title: json['title'] as String? ?? 'Conversation',
    messageCount: (json['message_count'] as num?)?.toInt() ?? messages.length,
    messages: messages
        .map((dynamic e) => chatMessageFromJson(e as Map<String, dynamic>))
        .toList(),
  );
}

UsageSummary usageSummaryFromJson(Map<String, dynamic> json) {
  return UsageSummary(
    requests: (json['requests'] as num?)?.toInt() ?? 0,
    cachedRequests: (json['cached_requests'] as num?)?.toInt() ?? 0,
    totalTokens: (json['total_tokens'] as num?)?.toInt() ?? 0,
    costUsd: (json['cost_usd'] as num?)?.toDouble() ?? 0,
  );
}
