import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import 'core/config/app_config.dart';
import 'core/di/injector.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/presentation/cubit/auth_cubit.dart';
import 'features/auth/presentation/cubit/auth_state.dart';
import 'features/auth/presentation/pages/login_page.dart';
import 'features/auth/presentation/pages/profile_page.dart';

/// Root application widget. Provides the AuthCubit and routes between the
/// authenticated (profile) and unauthenticated (login) experiences.
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
        child: const _AuthGate(),
      ),
    );
  }
}

class _AuthGate extends StatelessWidget {
  const _AuthGate();

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<AuthCubit, AuthState>(
      builder: (context, state) {
        switch (state.status) {
          case AuthStatus.authenticated:
            return const ProfilePage();
          case AuthStatus.unknown:
            return const Scaffold(body: Center(child: CircularProgressIndicator()));
          case AuthStatus.authenticating:
          case AuthStatus.unauthenticated:
            return const LoginPage();
        }
      },
    );
  }
}
