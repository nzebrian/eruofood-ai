import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/catalog_remote_data_source.dart';
import 'data/repositories/catalog_repository_impl.dart';
import 'domain/repositories/catalog_repository.dart';
import 'presentation/cubit/catalog_cubit.dart';

/// Registers the catalog feature's dependency graph.
void registerCatalogFeature(GetIt sl) {
  sl.registerLazySingleton<CatalogRemoteDataSource>(() => CatalogRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<CatalogRepository>(
    () => CatalogRepositoryImpl(sl<CatalogRemoteDataSource>()),
  );
  sl.registerFactory(() => CatalogCubit(sl<CatalogRepository>()));
}
