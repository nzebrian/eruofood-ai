import 'package:equatable/equatable.dart';

import '../../domain/entities/ai_entities.dart';

enum ChatStatus { idle, sending, error }

class ChatState extends Equatable {
  const ChatState({
    this.status = ChatStatus.idle,
    this.messages = const <ChatMessage>[],
    this.conversationId,
    this.error,
  });

  final ChatStatus status;
  final List<ChatMessage> messages;
  final String? conversationId;
  final String? error;

  ChatState copyWith({
    ChatStatus? status,
    List<ChatMessage>? messages,
    String? conversationId,
    String? error,
  }) {
    return ChatState(
      status: status ?? this.status,
      messages: messages ?? this.messages,
      conversationId: conversationId ?? this.conversationId,
      error: error,
    );
  }

  @override
  List<Object?> get props => <Object?>[status, messages, conversationId, error];
}
