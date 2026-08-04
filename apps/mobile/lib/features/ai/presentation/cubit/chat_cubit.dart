import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/ai_entities.dart';
import '../../domain/repositories/ai_repository.dart';
import 'chat_state.dart';

/// Drives the Smart Cooking Assistant chat screen, keeping the running message
/// list and the server conversation id so turns continue the same thread.
class ChatCubit extends Cubit<ChatState> {
  ChatCubit(this._repository) : super(const ChatState());

  final AiRepository _repository;

  Future<void> send(String message) async {
    final trimmed = message.trim();
    if (trimmed.isEmpty || state.status == ChatStatus.sending) {
      return;
    }

    final withUser = <ChatMessage>[
      ...state.messages,
      ChatMessage(role: 'user', content: trimmed),
    ];
    emit(state.copyWith(status: ChatStatus.sending, messages: withUser));

    final result = await _repository.chat(trimmed, conversationId: state.conversationId);
    result.fold(
      (failure) => emit(state.copyWith(status: ChatStatus.error, error: failure.message)),
      (turn) => emit(
        state.copyWith(
          status: ChatStatus.idle,
          conversationId: turn.conversationId,
          messages: <ChatMessage>[
            ...withUser,
            ChatMessage(role: 'assistant', content: turn.reply),
          ],
        ),
      ),
    );
  }
}
