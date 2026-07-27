import '../../domain/entities/notifications_entities.dart';

AppNotification notificationFromJson(Map<String, dynamic> json) {
  return AppNotification(
    id: json['id'] as String? ?? '',
    category: json['category'] as String? ?? 'admin',
    subject: json['subject'] as String? ?? '',
    body: json['body'] as String? ?? '',
    read: json['read'] as bool? ?? false,
    createdAt: json['created_at'] as String? ?? '',
  );
}

ConversationView conversationFromJson(Map<String, dynamic> json) {
  return ConversationView(
    id: json['id'] as String? ?? '',
    type: json['type'] as String? ?? '',
    subject: json['subject'] as String?,
  );
}

MessageView messageFromJson(Map<String, dynamic> json) {
  return MessageView(
    id: json['id'] as String? ?? '',
    senderId: json['sender_id'] as String? ?? '',
    body: json['body'] as String? ?? '',
    createdAt: json['created_at'] as String? ?? '',
  );
}
