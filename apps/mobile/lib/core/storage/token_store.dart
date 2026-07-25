import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists auth tokens in the platform's secure storage (Keychain / Keystore).
/// Abstracted so the storage mechanism can change without touching features.
abstract class TokenStore {
  Future<void> save({required String accessToken, required String refreshToken});
  Future<String?> accessToken();
  Future<String?> refreshToken();
  Future<void> clear();
}

class SecureTokenStore implements TokenStore {
  SecureTokenStore(this._storage);

  final FlutterSecureStorage _storage;

  static const _accessKey = 'eruofood_access_token';
  static const _refreshKey = 'eruofood_refresh_token';

  @override
  Future<void> save({required String accessToken, required String refreshToken}) async {
    await _storage.write(key: _accessKey, value: accessToken);
    await _storage.write(key: _refreshKey, value: refreshToken);
  }

  @override
  Future<String?> accessToken() => _storage.read(key: _accessKey);

  @override
  Future<String?> refreshToken() => _storage.read(key: _refreshKey);

  @override
  Future<void> clear() async {
    await _storage.delete(key: _accessKey);
    await _storage.delete(key: _refreshKey);
  }
}
