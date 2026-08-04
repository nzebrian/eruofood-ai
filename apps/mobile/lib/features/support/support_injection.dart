import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/support_remote_data_source.dart';
import 'data/repositories/support_repository_impl.dart';
import 'domain/repositories/support_repository.dart';

/// Registers the Customer Support feature's dependency graph.
void registerSupportFeature(GetIt sl) {
  sl.registerLazySingleton<SupportRemoteDataSource>(() => SupportRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<SupportRepository>(
    () => SupportRepositoryImpl(sl<SupportRemoteDataSource>()),
  );
}
