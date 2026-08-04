import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/search_remote_data_source.dart';
import 'data/repositories/search_repository_impl.dart';
import 'domain/repositories/search_repository.dart';

/// Registers the Search feature's dependency graph.
void registerSearchFeature(GetIt sl) {
  sl.registerLazySingleton<SearchRemoteDataSource>(() => SearchRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<SearchRepository>(
    () => SearchRepositoryImpl(sl<SearchRemoteDataSource>()),
  );
}
