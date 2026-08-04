import 'package:eruofood/features/auth/data/models/auth_user_model.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('AuthUserModel', () {
    test('maps API JSON to the domain entity', () {
      final model = AuthUserModel.fromJson(<String, dynamic>{
        'id': '0193f8a0-1111-7abc-8def-0123456789ab',
        'name': 'Ada Lovelace',
        'email': 'ada@example.com',
        'phone': null,
        'email_verified': true,
        'roles': <dynamic>['user'],
        'two_factor_enabled': false,
        'avatar_url': null,
      });

      expect(model.email, 'ada@example.com');
      expect(model.emailVerified, isTrue);
      expect(model.roles, <String>['user']);
      expect(model.twoFactorEnabled, isFalse);
    });
  });
}
