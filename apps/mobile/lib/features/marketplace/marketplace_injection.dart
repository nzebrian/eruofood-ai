import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/marketplace_remote_data_source.dart';
import 'data/repositories/marketplace_repository_impl.dart';
import 'domain/repositories/marketplace_repository.dart';

/// Registers the marketplace feature's dependency graph.
void registerMarketplaceFeature(GetIt sl) {
  sl.registerLazySingleton<MarketplaceRemoteDataSource>(() => MarketplaceRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<MarketplaceRepository>(
    () => MarketplaceRepositoryImpl(sl<MarketplaceRemoteDataSource>()),
  );
}
