import 'package:flutter/material.dart';

/// Central Material 3 theme. Design tokens live here so the whole app stays
/// visually consistent.
class AppTheme {
  const AppTheme._();

  static ThemeData light() {
    return ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF1B7A43)),
    );
  }
}
