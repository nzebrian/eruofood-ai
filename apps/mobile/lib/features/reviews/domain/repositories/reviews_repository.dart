import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/reviews_entities.dart';

/// Contract for the Reviews & Ratings feature. Every review interaction flows
/// through the Reviews context — no other feature stores or aggregates reviews.
abstract class ReviewsRepository {
  Future<Either<Failure, List<ReviewView>>> forSubject(
    String subjectType,
    String subjectId, {
    String sort,
    bool verifiedOnly,
  });

  Future<Either<Failure, RatingSummaryView>> summary(String subjectType, String subjectId);

  Future<Either<Failure, ReviewView>> submit(
    String subjectType,
    String subjectId,
    int rating,
    String? title,
    String? body,
  );

  Future<Either<Failure, ReviewView>> vote(String id, bool helpful);
}
