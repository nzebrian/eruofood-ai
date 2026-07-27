import 'package:equatable/equatable.dart';

/// An in-app notification.
class AppNotification extends Equatable {
  const AppNotification({
    required this.id,
    required this.category,
    required this.subject,
    required this.body,
    required this.read,
    required this.createdAt,
  });

  final String id;
  final String category;
  final String subject;
  final String body;
  final bool read;
  final String createdAt;

  @override
  List<Object?> get props => <Object?>[id, category, subject, body, read, createdAt];
}

/// A conversation in the inbox.
class ConversationView extends Equatable {
  const ConversationView({required this.id, required this.type, this.subject});

  final String id;
  final String type;
  final String? subject;

  @override
  List<Object?> get props => <Object?>[id, type, subject];
}

/// A chat message.
class MessageView extends Equatable {
  const MessageView({
    required this.id,
    required this.senderId,
    required this.body,
    required this.createdAt,
  });

  final String id;
  final String senderId;
  final String body;
  final String createdAt;

  @override
  List<Object?> get props => <Object?>[id, senderId, body, createdAt];
}
