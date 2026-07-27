import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/commerce_remote_data_source.dart';
import 'data/repositories/commerce_repository_impl.dart';
import 'domain/repositories/commerce_repository.dart';

/// Registers the Commerce (Marketplace/Grocery) feature's dependency graph.
void registerCommerceFeature(GetIt sl) {
  sl.registerLazySingleton<CommerceRemoteDataSource>(() => CommerceRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<CommerceRepository>(
    () => CommerceRepositoryImpl(sl<CommerceRemoteDataSource>()),
  );
}
