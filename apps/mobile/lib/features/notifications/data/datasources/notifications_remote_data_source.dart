import '../../../../core/network/api_client.dart';
import '../../domain/entities/notifications_entities.dart';
import '../models/notifications_models.dart';

/// Reads the Notifications REST endpoints (mounted at /notifications).
class NotificationsRemoteDataSource {
  NotificationsRemoteDataSource(this._client);

  final ApiClient _client;

  Map<String, dynamic> _item(dynamic res) =>
      (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;

  List<T> _list<T>(dynamic res, T Function(Map<String, dynamic>) map) {
    final data = (res.data as Map<String, dynamic>)['data'] as List<dynamic>;
    return data.map((dynamic e) => map(e as Map<String, dynamic>)).toList();
  }

  Future<List<AppNotification>> notifications() async {
    final res = await _client.get<dynamic>('/notifications');
    return _list(res, notificationFromJson);
  }

  Future<int> unreadCount() async {
    final res = await _client.get<dynamic>('/notifications/unread-count');
    return (_item(res)['unread'] as num?)?.toInt() ?? 0;
  }

  Future<void> markRead(String id) async {
    await _client.post<dynamic>('/notifications/$id/read');
  }

  Future<List<ConversationView>> conversations() async {
    final res = await _client.get<dynamic>('/notifications/conversations');
    return _list(res, conversationFromJson);
  }

  Future<List<MessageView>> messages(String conversationId) async {
    final res = await _client.get<dynamic>('/notifications/conversations/$conversationId/messages');
    return _list(res, messageFromJson);
  }

  Future<MessageView> send(String conversationId, String body) async {
    final res = await _client.post<dynamic>(
      '/notifications/conversations/$conversationId/messages',
      data: <String, dynamic>{'body': body},
    );
    return messageFromJson(_item(res));
  }
}
