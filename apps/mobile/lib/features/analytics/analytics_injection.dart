import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/analytics_remote_data_source.dart';
import 'data/repositories/analytics_repository_impl.dart';
import 'domain/repositories/analytics_repository.dart';

/// Registers the Analytics feature's dependency graph.
void registerAnalyticsFeature(GetIt sl) {
  sl.registerLazySingleton<AnalyticsRemoteDataSource>(() => AnalyticsRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<AnalyticsRepository>(
    () => AnalyticsRepositoryImpl(sl<AnalyticsRemoteDataSource>()),
  );
}
