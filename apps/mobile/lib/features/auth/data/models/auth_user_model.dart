import '../../domain/entities/auth_user.dart';

/// Data model that maps the API's user JSON to the domain entity.
class AuthUserModel extends AuthUser {
  const AuthUserModel({
    required super.id,
    required super.name,
    required super.email,
    required super.emailVerified,
    required super.roles,
    required super.twoFactorEnabled,
    super.phone,
    super.avatarUrl,
  });

  factory AuthUserModel.fromJson(Map<String, dynamic> json) {
    return AuthUserModel(
      id: json['id'] as String,
      name: json['name'] as String,
      email: json['email'] as String,
      phone: json['phone'] as String?,
      emailVerified: json['email_verified'] as bool? ?? false,
      roles: (json['roles'] as List<dynamic>? ?? <dynamic>[]).map((dynamic r) => r as String).toList(),
      twoFactorEnabled: json['two_factor_enabled'] as bool? ?? false,
      avatarUrl: json['avatar_url'] as String?,
    );
  }
}
