import 'package:equatable/equatable.dart';

/// A subject owner's public response to a review.
class OwnerResponseView extends Equatable {
  const OwnerResponseView({required this.body, required this.respondedAt});

  final String body;
  final String respondedAt;

  @override
  List<Object?> get props => <Object?>[body, respondedAt];
}

/// A single review of a subject.
class ReviewView extends Equatable {
  const ReviewView({
    required this.id,
    required this.subjectType,
    required this.subjectId,
    required this.rating,
    required this.title,
    required this.body,
    required this.verifiedPurchase,
    required this.status,
    required this.helpfulYes,
    required this.ownerResponse,
    required this.createdAt,
  });

  final String id;
  final String subjectType;
  final String subjectId;
  final int rating;
  final String? title;
  final String? body;
  final bool verifiedPurchase;
  final String status;
  final int helpfulYes;
  final OwnerResponseView? ownerResponse;
  final String createdAt;

  @override
  List<Object?> get props => <Object?>[id, rating, status, helpfulYes, verifiedPurchase];
}

/// The authoritative rating summary for a subject.
class RatingSummaryView extends Equatable {
  const RatingSummaryView({
    required this.count,
    required this.average,
    required this.distribution,
    required this.verifiedCount,
  });

  final int count;
  final double average;
  final Map<int, int> distribution;
  final int verifiedCount;

  @override
  List<Object?> get props => <Object?>[count, average, distribution, verifiedCount];
}
