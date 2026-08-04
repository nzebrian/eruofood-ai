import '../../domain/entities/reviews_entities.dart';

OwnerResponseView? ownerResponseFromJson(Map<String, dynamic>? json) {
  if (json == null) return null;
  return OwnerResponseView(
    body: json['body'] as String? ?? '',
    respondedAt: json['responded_at'] as String? ?? '',
  );
}

ReviewView reviewFromJson(Map<String, dynamic> json) {
  return ReviewView(
    id: json['id'] as String? ?? '',
    subjectType: json['subject_type'] as String? ?? '',
    subjectId: json['subject_id'] as String? ?? '',
    rating: (json['rating'] as num?)?.toInt() ?? 0,
    title: json['title'] as String?,
    body: json['body'] as String?,
    verifiedPurchase: json['verified_purchase'] as bool? ?? false,
    status: json['status'] as String? ?? 'pending',
    helpfulYes: (json['helpful_yes'] as num?)?.toInt() ?? 0,
    ownerResponse: ownerResponseFromJson(json['owner_response'] as Map<String, dynamic>?),
    createdAt: json['created_at'] as String? ?? '',
  );
}

RatingSummaryView ratingSummaryFromJson(Map<String, dynamic> json) {
  final rawDistribution = json['distribution'] as Map<String, dynamic>? ?? <String, dynamic>{};
  final distribution = <int, int>{};
  rawDistribution.forEach((String key, dynamic value) {
    distribution[int.tryParse(key) ?? 0] = (value as num?)?.toInt() ?? 0;
  });
  return RatingSummaryView(
    count: (json['count'] as num?)?.toInt() ?? 0,
    average: (json['average'] as num?)?.toDouble() ?? 0.0,
    distribution: distribution,
    verifiedCount: (json['verified_count'] as num?)?.toInt() ?? 0,
  );
}
