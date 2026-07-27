import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/notifications_entities.dart';

/// Contract for the Notifications & messaging feature.
abstract class NotificationsRepository {
  Future<Either<Failure, List<AppNotification>>> notifications();

  Future<Either<Failure, int>> unreadCount();

  Future<Either<Failure, Unit>> markRead(String id);

  Future<Either<Failure, List<ConversationView>>> conversations();

  Future<Either<Failure, List<MessageView>>> messages(String conversationId);

  Future<Either<Failure, MessageView>> send(String conversationId, String body);
}
