import 'package:flutter/material.dart';

import 'core/config/app_config.dart';
import 'core/di/injector.dart';
import 'core/theme/app_theme.dart';

/// Root application widget. Intentionally minimal in the foundation phase —
/// routing and feature screens are composed here as features arrive.
class EruoFoodApp extends StatelessWidget {
  const EruoFoodApp({super.key});

  @override
  Widget build(BuildContext context) {
    final AppConfig config = sl<AppConfig>();

    return MaterialApp(
      title: config.appName,
      theme: AppTheme.light(),
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        appBar: AppBar(title: Text(config.appName)),
        body: Center(
          child: Text('Enterprise foundation ready — env: ${config.appEnv}'),
        ),
      ),
    );
  }
}
