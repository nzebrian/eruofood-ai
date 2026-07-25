import 'package:eruofood/core/config/app_config.dart';
import 'package:eruofood/core/network/api_client.dart';
import 'package:flutter_test/flutter_test.dart';

// Foundation smoke tests. Widget tests that boot the full app are added once
// features and their DI registrations exist.
void main() {
  group('AppConfig', () {
    test('builds an ApiClient with the configured base URL', () {
      const config = AppConfig(
        apiBaseUrl: 'http://localhost/api/v1',
        appName: 'EruoFood AI',
        appEnv: 'test',
      );

      final client = ApiClient(config);

      expect(client.raw.options.baseUrl, 'http://localhost/api/v1');
    });
  });
}
