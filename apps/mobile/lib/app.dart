import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'core/config/app_config.dart';
import 'core/di/injector.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/presentation/cubit/auth_cubit.dart';
import 'features/auth/presentation/cubit/auth_state.dart';
import 'features/auth/presentation/pages/login_page.dart';
import 'features/auth/presentation/pages/profile_page.dart';
import 'features/catalog/presentation/pages/favourites_page.dart';
import 'features/catalog/presentation/pages/food_catalogue_page.dart';

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

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<AuthCubit, AuthState>(
      builder: (context, state) {
        final authenticated = state.status == AuthStatus.authenticated;

        final pages = <Widget>[
          const FoodCataloguePage(),
          authenticated ? const FavouritesPage() : const _AuthPrompt(message: 'Sign in to see favourites.'),
          authenticated ? const ProfilePage() : const LoginPage(),
        ];

        return Scaffold(
          appBar: _index == 0 ? AppBar(title: const Text('EruoFood AI')) : null,
          body: IndexedStack(index: _index, children: pages),
          bottomNavigationBar: NavigationBar(
            selectedIndex: _index,
            onDestinationSelected: (i) => setState(() => _index = i),
            destinations: const <NavigationDestination>[
              NavigationDestination(icon: Icon(Icons.restaurant_menu), label: 'Foods'),
              NavigationDestination(icon: Icon(Icons.favorite_border), label: 'Favourites'),
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
