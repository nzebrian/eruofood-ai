import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/support_entities.dart';
import '../../domain/repositories/support_repository.dart';
import '../datasources/support_remote_data_source.dart';

class SupportRepositoryImpl implements SupportRepository {
  SupportRepositoryImpl(this._remote);

  final SupportRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<TicketSummaryView>>> myTickets() => _guard(() => _remote.myTickets());

  @override
  Future<Either<Failure, TicketView>> ticket(String id) => _guard(() => _remote.ticket(id));

  @override
  Future<Either<Failure, TicketView>> openTicket(String subject, String category, String body, String priority) =>
      _guard(() => _remote.openTicket(subject, category, body, priority));

  @override
  Future<Either<Failure, TicketView>> reply(String id, String body) => _guard(() => _remote.reply(id, body));

  @override
  Future<Either<Failure, Unit>> submitCsat(String id, int score, String? comment) =>
      _guard(() async {
        await _remote.submitCsat(id, score, comment);
        return unit;
      });

  @override
  Future<Either<Failure, List<ArticleView>>> articles(String query) => _guard(() => _remote.articles(query));

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
