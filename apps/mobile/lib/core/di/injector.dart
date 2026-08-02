import 'package:get_it/get_it.dart';

import '../../features/admin/admin_injection.dart';
import '../../features/ai/ai_injection.dart';
import '../../features/auth/auth_injection.dart';
import '../../features/catalog/catalog_injection.dart';
import '../../features/commerce/commerce_injection.dart';
import '../../features/analytics/analytics_injection.dart';
import '../../features/notifications/notifications_injection.dart';
import '../../features/payments/payments_injection.dart';
import '../../features/marketplace/marketplace_injection.dart';
import '../../features/nutrition/nutrition_injection.dart';
import '../../features/loyalty/loyalty_injection.dart';
import '../../features/reviews/reviews_injection.dart';
import '../../features/search/search_injection.dart';
import '../../features/support/support_injection.dart';
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
  registerCatalogFeature(sl);
  registerAiFeature(sl);
  registerNutritionFeature(sl);
  registerMarketplaceFeature(sl);
  registerCommerceFeature(sl);
  registerPaymentsFeature(sl);
  registerNotificationsFeature(sl);
  registerAnalyticsFeature(sl);
  registerAdminFeature(sl);
  registerSearchFeature(sl);
  registerSupportFeature(sl);
  registerReviewsFeature(sl);
  registerLoyaltyFeature(sl);
}
