import 'package:get_it/get_it.dart';

import '../../core/network/api_client.dart';
import 'data/datasources/nutrition_remote_data_source.dart';
import 'data/repositories/nutrition_repository_impl.dart';
import 'domain/repositories/nutrition_repository.dart';

/// Registers the nutrition feature's dependency graph.
void registerNutritionFeature(GetIt sl) {
  sl.registerLazySingleton<NutritionRemoteDataSource>(() => NutritionRemoteDataSource(sl<ApiClient>()));
  sl.registerLazySingleton<NutritionRepository>(
    () => NutritionRepositoryImpl(sl<NutritionRemoteDataSource>()),
  );
}
