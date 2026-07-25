import 'package:get_it/get_it.dart';

import '../config/app_config.dart';
import '../network/api_client.dart';

/// Global service locator. Registrations here wire ports to their
/// implementations (Dependency Injection). Each feature registers its own
/// data sources, repositories, and use cases via its registration function.
final GetIt sl = GetIt.instance;

Future<void> configureDependencies() async {
  // Core / infrastructure singletons.
  final AppConfig config = AppConfig.fromEnv();
  sl
    ..registerSingleton<AppConfig>(config)
    ..registerLazySingleton<ApiClient>(() => ApiClient(sl<AppConfig>()));

  // Feature registrations are added here as features are introduced, e.g.:
  //   registerCatalogFeature(sl);
}
