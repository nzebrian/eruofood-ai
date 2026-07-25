import 'package:get_it/get_it.dart';

import '../../features/auth/auth_injection.dart';
import '../config/app_config.dart';
import '../network/api_client.dart';
import '../storage/token_store.dart';

/// Global service locator. Registrations here wire ports to their
/// implementations (Dependency Injection). Each feature registers its own
/// data sources, repositories, and use cases via its registration function.
final GetIt sl = GetIt.instance;

Future<void> configureDependencies() async {
  // Core / infrastructure singletons.
  final AppConfig config = AppConfig.fromEnv();
  sl
    ..registerSingleton<AppConfig>(config)
    ..registerLazySingleton<ApiClient>(
      () => ApiClient(
        sl<AppConfig>(),
        // Resolved lazily per request so the token store need not exist yet.
        tokenProvider: () => sl<TokenStore>().accessToken(),
      ),
    );

  // Feature registrations.
  registerAuthFeature(sl);
}
