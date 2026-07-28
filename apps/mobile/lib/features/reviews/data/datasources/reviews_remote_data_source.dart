import '../../../../core/network/api_client.dart';
import '../../domain/entities/reviews_entities.dart';
import '../models/reviews_models.dart';

/// Reads the Reviews & Ratings REST endpoints (mounted at /reviews).
class ReviewsRemoteDataSource {
  ReviewsRemoteDataSource(this._client);

  final ApiClient _client;

  Future<List<ReviewView>> forSubject(
    String subjectType,
    String subjectId, {
    String sort = 'newest',
    bool verifiedOnly = false,
  }) async {
    final res = await _client.get<dynamic>(
      '/reviews/$subjectType/$subjectId',
      query: <String, dynamic>{'sort': sort, if (verifiedOnly) 'verified': 'true'},
    );
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => reviewFromJson(e as Map<String, dynamic>)).toList();
  }

  Future<RatingSummaryView> summary(String subjectType, String subjectId) async {
    final res = await _client.get<dynamic>('/reviews/$subjectType/$subjectId/summary');
    return ratingSummaryFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<ReviewView> submit(String subjectType, String subjectId, int rating, String? title, String? body) async {
    final res = await _client.post<dynamic>('/reviews', data: <String, dynamic>{
      'subject_type': subjectType,
      'subject_id': subjectId,
      'rating': rating,
      if (title != null) 'title': title,
      if (body != null) 'body': body,
    });
    return reviewFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<ReviewView> vote(String id, bool helpful) async {
    final res = await _client.post<dynamic>('/reviews/$id/vote', data: <String, dynamic>{'helpful': helpful});
    return reviewFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }
}
