import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/admin_entities.dart';
import '../../domain/repositories/admin_repository.dart';
import '../datasources/admin_remote_data_source.dart';

class AdminRepositoryImpl implements AdminRepository {
  AdminRepositoryImpl(this._remote);

  final AdminRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<AuditEntryView>>> recentAudit() =>
      _guard(() => _remote.recentAudit());

  @override
  Future<Either<Failure, List<TicketView>>> tickets(String status) =>
      _guard(() => _remote.tickets(status));

  @override
  Future<Either<Failure, TicketView>> replyTicket(String id, String body) =>
      _guard(() => _remote.replyTicket(id, body));

  @override
  Future<Either<Failure, TicketView>> resolveTicket(String id) =>
      _guard(() => _remote.resolveTicket(id));

  Future<Either<Failure, T>> _guard<T>(Future<T> Function() call) async {
    try {
      return Right<Failure, T>(await call());
    } on DioException catch (e) {
      final dynamic data = e.response?.data;
      final message = data is Map<String, dynamic>
          ? (data['error']?['message']?.toString() ?? e.message ?? 'Network error.')
          : (e.message ?? 'Network error.');
      return Left<Failure, T>(ServerFailure(message));
    }
  }
}
