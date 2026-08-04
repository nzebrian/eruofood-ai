import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/reviews_remote_data_source.dart';
import 'data/repositories/reviews_repository_impl.dart';
import 'domain/repositories/reviews_repository.dart';

/// Registers the Reviews & Ratings feature's dependency graph.
void registerReviewsFeature(GetIt sl) {
  sl.registerLazySingleton<ReviewsRemoteDataSource>(() => ReviewsRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<ReviewsRepository>(
    () => ReviewsRepositoryImpl(sl<ReviewsRemoteDataSource>()),
  );
}
