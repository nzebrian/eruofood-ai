import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'core/config/app_config.dart';
import 'core/di/injector.dart';
import 'core/resilience/retry_queue_processor.dart';
import 'core/theme/app_theme.dart';
import 'features/admin/presentation/pages/admin_overview_page.dart';
import 'features/ai/presentation/pages/ai_hub_page.dart';
import 'features/analytics/presentation/pages/analytics_summary_page.dart';
import 'features/auth/presentation/cubit/auth_cubit.dart';
import 'features/auth/presentation/cubit/auth_state.dart';
import 'features/auth/presentation/pages/login_page.dart';
import 'features/auth/presentation/pages/profile_page.dart';
import 'features/catalog/presentation/pages/food_catalogue_page.dart';
import 'features/commerce/presentation/pages/shop_page.dart';
import 'features/marketplace/presentation/pages/marketplace_hub_page.dart';
import 'features/notifications/presentation/pages/notification_centre_page.dart';
import 'features/nutrition/presentation/pages/nutrition_hub_page.dart';
import 'features/payments/presentation/pages/wallet_page.dart';
import 'features/search/presentation/pages/search_page.dart';
import 'features/support/presentation/pages/support_page.dart';

/// Root application widget: provides the AuthCubit and the tabbed home shell.
class EruoFoodApp extends StatelessWidget {
  const EruoFoodApp({super.key});

  @override
  Widget build(BuildContext context) {
    final AppConfig config = sl<AppConfig>();

    return MaterialApp(
      title: config.appName,
      theme: AppTheme.light(),
      debugShowCheckedModeBanner: false,
      home: BlocProvider<AuthCubit>(
        create: (_) => sl<AuthCubit>()..bootstrap(),
        child: const HomeShell(),
      ),
    );
  }
}

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with WidgetsBindingObserver {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Coming back to the foreground is the app's honest substitute for a
    // connectivity event: this project has no connectivity plugin, and a timer
    // that polled for one would be a retry storm with extra steps.
    if (state == AppLifecycleState.resumed) _drainRetryQueue();
  }

  /// Ask the server about anything left unresolved, and resend only what it
  /// says is safe to resend.
  ///
  /// Deliberately not awaited — this is background reconciliation and no screen
  /// should wait on it. Calling it twice is safe: the processor's single-flight
  /// guard turns the second call into a no-op rather than a duplicate send.
  void _drainRetryQueue() {
    if (!sl.isRegistered<RetryQueueProcessor>()) return;
    unawaited(sl<RetryQueueProcessor>().process());
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<AuthCubit, AuthState>(
      // Reconciliation needs an account: `POST /reconcile` answers on
      // (account, scope, key), never on the key alone. So the queue is drained
      // the moment a session exists, and not before.
      listenWhen: (AuthState previous, AuthState current) =>
          previous.status != AuthStatus.authenticated &&
          current.status == AuthStatus.authenticated,
      listener: (_, __) => _drainRetryQueue(),
      builder: (context, state) {
        final authenticated = state.status == AuthStatus.authenticated;

        final pages = <Widget>[
          const FoodCataloguePage(),
          authenticated
              ? const MarketplaceHubPage()
              : const _AuthPrompt(message: 'Sign in to order food.'),
          const ShopPage(),
          authenticated
              ? const WalletPage()
              : const _AuthPrompt(message: 'Sign in to use your wallet.'),
          authenticated ? const AiHubPage() : const _AuthPrompt(message: 'Sign in to use the AI features.'),
          authenticated
              ? const NutritionHubPage()
              : const _AuthPrompt(message: 'Sign in to use the nutrition features.'),
          authenticated ? const ProfilePage() : const LoginPage(),
        ];

        return Scaffold(
          appBar: _index == 0
              ? AppBar(
                  title: const Text('EruoFood AI'),
                  actions: <Widget>[
                    IconButton(
                      icon: const Icon(Icons.search),
                      tooltip: 'Search',
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(builder: (_) => const SearchPage()),
                      ),
                    ),
                    if (authenticated) ...<Widget>[
                          IconButton(
                            icon: const Icon(Icons.insights_outlined),
                            tooltip: 'Analytics',
                            onPressed: () => Navigator.of(context).push(
                              MaterialPageRoute<void>(builder: (_) => const AnalyticsSummaryPage()),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.support_agent_outlined),
                            tooltip: 'Support',
                            onPressed: () => Navigator.of(context).push(
                              MaterialPageRoute<void>(builder: (_) => const SupportPage()),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.notifications_outlined),
                            onPressed: () => Navigator.of(context).push(
                              MaterialPageRoute<void>(builder: (_) => const NotificationCentrePage()),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.admin_panel_settings_outlined),
                            tooltip: 'Administration',
                            onPressed: () => Navigator.of(context).push(
                              MaterialPageRoute<void>(builder: (_) => const AdminOverviewPage()),
                            ),
                          ),
                        ],
                  ],
                )
              : null,
          body: IndexedStack(index: _index, children: pages),
          bottomNavigationBar: NavigationBar(
            selectedIndex: _index,
            onDestinationSelected: (i) => setState(() => _index = i),
            destinations: const <NavigationDestination>[
              NavigationDestination(icon: Icon(Icons.restaurant_menu), label: 'Foods'),
              NavigationDestination(icon: Icon(Icons.storefront_outlined), label: 'Order'),
              NavigationDestination(icon: Icon(Icons.shopping_bag_outlined), label: 'Shop'),
              NavigationDestination(icon: Icon(Icons.account_balance_wallet_outlined), label: 'Wallet'),
              NavigationDestination(icon: Icon(Icons.auto_awesome), label: 'AI'),
              NavigationDestination(icon: Icon(Icons.monitor_heart_outlined), label: 'Health'),
              NavigationDestination(icon: Icon(Icons.person_outline), label: 'Account'),
            ],
          ),
        );
      },
    );
  }
}

class _AuthPrompt extends StatelessWidget {
  const _AuthPrompt({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Center(child: Text(message));
  }
}
