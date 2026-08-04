import 'package:dio/dio.dart';

import '../../../../core/network/api_client.dart';
import '../../domain/entities/ai_entities.dart';
import '../models/ai_models.dart';

/// Reads the AI Engine REST endpoints via the shared ApiClient.
class AiRemoteDataSource {
  AiRemoteDataSource(this._client);

  final ApiClient _client;

  Map<String, dynamic> _item(Response<dynamic> res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  Future<AiRecipeResult> generateRecipe({
    required String dishName,
    int servings = 4,
    String? difficulty,
    List<String> dietary = const <String>[],
    List<String> ingredients = const <String>[],
  }) async {
    final res = await _client.post<dynamic>('/ai/recipes/generate', data: <String, dynamic>{
      'dish_name': dishName,
      'servings': servings,
      if (difficulty != null && difficulty.isNotEmpty) 'difficulty': difficulty,
      'dietary_preferences': dietary,
      'available_ingredients': ingredients,
    });
    return aiRecipeResultFromJson(_item(res));
  }

  Future<ChatTurn> chat(String message, {String? conversationId}) async {
    final res = await _client.post<dynamic>('/ai/assistant/chat', data: <String, dynamic>{
      'message': message,
      if (conversationId != null) 'conversation_id': conversationId,
    });
    return chatTurnFromJson(_item(res));
  }

  Future<List<ConversationSummary>> conversations() async {
    final res = await _client.get<dynamic>('/ai/conversations');
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data
        .map((dynamic e) => conversationSummaryFromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<Conversation> conversation(String id) async {
    final res = await _client.get<dynamic>('/ai/conversations/$id');
    return conversationFromJson(_item(res));
  }

  Future<void> deleteConversation(String id) =>
      _client.raw.delete<dynamic>('/ai/conversations/$id');

  Future<UsageSummary> usage({int days = 30}) async {
    final res = await _client.get<dynamic>('/ai/usage', query: <String, dynamic>{'days': days});
    return usageSummaryFromJson(_item(res));
  }
}
