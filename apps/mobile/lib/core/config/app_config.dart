import 'package:flutter_dotenv/flutter_dotenv.dart';

/// Typed access to runtime configuration loaded from the bundled `.env` asset.
///
/// Centralising config here keeps `dotenv` lookups out of feature code and
/// provides a single validation point at startup.
class AppConfig {
  const AppConfig({
    required this.apiBaseUrl,
    required this.appName,
    required this.appEnv,
  });

  final String apiBaseUrl;
  final String appName;
  final String appEnv;

  factory AppConfig.fromEnv() {
    return AppConfig(
      apiBaseUrl: dotenv.get('API_BASE_URL', fallback: 'http://10.0.2.2:8080/api/v1'),
      appName: dotenv.get('APP_NAME', fallback: 'EruoFood AI'),
      appEnv: dotenv.get('APP_ENV', fallback: 'local'),
    );
  }
}
