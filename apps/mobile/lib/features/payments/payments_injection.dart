import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/payments_remote_data_source.dart';
import 'data/repositories/payments_repository_impl.dart';
import 'domain/repositories/payments_repository.dart';

/// Registers the Payments feature's dependency graph.
void registerPaymentsFeature(GetIt sl) {
  sl.registerLazySingleton<PaymentsRemoteDataSource>(() => PaymentsRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<PaymentsRepository>(
    () => PaymentsRepositoryImpl(sl<PaymentsRemoteDataSource>()),
  );
}
