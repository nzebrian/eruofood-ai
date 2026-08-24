import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:get_it/get_it.dart';

import '../../features/admin/admin_injection.dart';
import '../../features/ai/ai_injection.dart';
import '../../features/analytics/analytics_injection.dart';
import '../../features/auth/auth_injection.dart';
import '../../features/catalog/catalog_injection.dart';
import '../../features/commerce/commerce_injection.dart';
import '../../features/loyalty/loyalty_injection.dart';
import '../../features/marketplace/marketplace_injection.dart';
import '../../features/notifications/notifications_injection.dart';
import '../../features/nutrition/nutrition_injection.dart';
import '../../features/payments/payments_injection.dart';
import '../../features/reviews/reviews_injection.dart';
import '../../features/search/search_injection.dart';
import '../../features/support/support_injection.dart';
import '../config/app_config.dart';
import '../network/api_client.dart';
import '../resilience/idempotency_key.dart';
import '../resilience/reconciliation_gateway.dart';
import '../resilience/retry_queue.dart';
import '../resilience/retry_queue_interceptor.dart';
import '../resilience/retry_queue_processor.dart';
import '../resilience/secure_pending_operation_store.dart';
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
    ..registerLazySingleton<FlutterSecureStorage>(
      () => const FlutterSecureStorage(),
    )
    // Resilience. The store is the same secure storage the token store uses;
    // there is one queue and one persistence mechanism, not a second stack
    // alongside the existing one.
    ..registerLazySingleton<PendingOperationStore>(
      () => SecurePendingOperationStore(sl<FlutterSecureStorage>()),
    )
    ..registerLazySingleton<RetryQueue>(
      () => RetryQueue(sl<PendingOperationStore>()),
    )
    ..registerLazySingleton<ApiClient>(
      () => ApiClient(
        sl<AppConfig>(),
        // Resolved lazily per request so the token store need not exist yet.
        tokenProvider: () => sl<TokenStore>().accessToken(),
        interceptors: <Interceptor>[
          RetryQueueInterceptor(
            queue: sl<RetryQueue>(),
            newIdempotencyKey: newIdempotencyKey,
            now: () => DateTime.now().toUtc(),
          ),
        ],
      ),
    )
    ..registerLazySingleton<ReconciliationGateway>(
      () => ReconciliationGateway(sl<ApiClient>()),
    )
    // Not started here and not scheduled. `process()` is called by the app at
    // the moments it already has — cold start once authenticated, and resume —
    // because a queue that polls is a retry storm waiting for a bad network.
    ..registerLazySingleton<RetryQueueProcessor>(
      () => RetryQueueProcessor(
        queue: sl<RetryQueue>(),
        reconciliation: sl<ReconciliationGateway>(),
        client: sl<ApiClient>(),
        now: () => DateTime.now().toUtc(),
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
