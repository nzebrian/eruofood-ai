import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/notifications_entities.dart';
import '../../domain/repositories/notifications_repository.dart';
import '../datasources/notifications_remote_data_source.dart';

class NotificationsRepositoryImpl implements NotificationsRepository {
  NotificationsRepositoryImpl(this._remote);

  final NotificationsRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<AppNotification>>> notifications() =>
      _guard(() => _remote.notifications());

  @override
  Future<Either<Failure, int>> unreadCount() => _guard(() => _remote.unreadCount());

  @override
  Future<Either<Failure, Unit>> markRead(String id) =>
      _guard(() async {
        await _remote.markRead(id);
        return unit;
      });

  @override
  Future<Either<Failure, List<ConversationView>>> conversations() =>
      _guard(() => _remote.conversations());

  @override
  Future<Either<Failure, List<MessageView>>> messages(String conversationId) =>
      _guard(() => _remote.messages(conversationId));

  @override
  Future<Either<Failure, MessageView>> send(String conversationId, String body) =>
      _guard(() => _remote.send(conversationId, body));

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() action) async {
    try {
      return Right<Failure, T>(await action());
    } on DioException catch (e) {
      final data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
