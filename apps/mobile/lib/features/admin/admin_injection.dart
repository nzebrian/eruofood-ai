import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/admin_remote_data_source.dart';
import 'data/repositories/admin_repository_impl.dart';
import 'domain/repositories/admin_repository.dart';

/// Registers the Platform Administration feature's dependency graph.
void registerAdminFeature(GetIt sl) {
  sl.registerLazySingleton<AdminRemoteDataSource>(() => AdminRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<AdminRepository>(
    () => AdminRepositoryImpl(sl<AdminRemoteDataSource>()),
  );
}
