import 'package:dartz/dartz.dart';
import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failures.dart';
import '../../domain/entities/reviews_entities.dart';
import '../../domain/repositories/reviews_repository.dart';
import '../datasources/reviews_remote_data_source.dart';

class ReviewsRepositoryImpl implements ReviewsRepository {
  ReviewsRepositoryImpl(this._remote);

  final ReviewsRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<ReviewView>>> forSubject(
    String subjectType,
    String subjectId, {
    String sort = 'newest',
    bool verifiedOnly = false,
  }) =>
      _guard(() => _remote.forSubject(subjectType, subjectId, sort: sort, verifiedOnly: verifiedOnly));

  @override
  Future<Either<Failure, RatingSummaryView>> summary(String subjectType, String subjectId) =>
      _guard(() => _remote.summary(subjectType, subjectId));

  @override
  Future<Either<Failure, ReviewView>> submit(
    String subjectType,
    String subjectId,
    int rating,
    String? title,
    String? body,
  ) =>
      _guard(() => _remote.submit(subjectType, subjectId, rating, title, body));

  @override
  Future<Either<Failure, ReviewView>> vote(String id, bool helpful) => _guard(() => _remote.vote(id, helpful));

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
