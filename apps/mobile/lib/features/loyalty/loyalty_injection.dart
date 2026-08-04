import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/loyalty_remote_data_source.dart';
import 'data/repositories/loyalty_repository_impl.dart';
import 'domain/repositories/loyalty_repository.dart';

/// Registers the Loyalty, Rewards & Referrals feature's dependency graph.
void registerLoyaltyFeature(GetIt sl) {
  sl.registerLazySingleton<LoyaltyRemoteDataSource>(() => LoyaltyRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<LoyaltyRepository>(
    () => LoyaltyRepositoryImpl(sl<LoyaltyRemoteDataSource>()),
  );
}
