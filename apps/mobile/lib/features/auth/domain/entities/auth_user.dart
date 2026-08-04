import 'package:equatable/equatable.dart';

/// Domain entity representing the authenticated user.
class AuthUser extends Equatable {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    required this.emailVerified,
    required this.roles,
    required this.twoFactorEnabled,
    this.phone,
    this.avatarUrl,
  });

  final String id;
  final String name;
  final String email;
  final String? phone;
  final bool emailVerified;
  final List<String> roles;
  final bool twoFactorEnabled;
  final String? avatarUrl;

  @override
  List<Object?> get props => <Object?>[id, name, email, phone, emailVerified, roles, twoFactorEnabled, avatarUrl];
}
