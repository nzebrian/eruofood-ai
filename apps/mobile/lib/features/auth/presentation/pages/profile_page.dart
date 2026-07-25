import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../cubit/auth_cubit.dart';
import '../cubit/auth_state.dart';

class ProfilePage extends StatelessWidget {
  const ProfilePage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('My profile'),
        actions: <Widget>[
          IconButton(
            icon: const Icon(Icons.logout),
            onPressed: () => context.read<AuthCubit>().logout(),
          ),
        ],
      ),
      body: BlocBuilder<AuthCubit, AuthState>(
        builder: (context, state) {
          final user = state.user;
          if (user == null) {
            return const Center(child: CircularProgressIndicator());
          }
          return ListView(
            padding: const EdgeInsets.all(24),
            children: <Widget>[
              CircleAvatar(
                radius: 40,
                child: Text(
                  user.name.isNotEmpty ? user.name[0].toUpperCase() : '?',
                  style: const TextStyle(fontSize: 28),
                ),
              ),
              const SizedBox(height: 16),
              Center(child: Text(user.name, style: Theme.of(context).textTheme.headlineSmall)),
              const SizedBox(height: 24),
              _InfoTile(label: 'Email', value: user.email, trailing: user.emailVerified ? '✓ verified' : 'unverified'),
              _InfoTile(label: 'Phone', value: user.phone ?? '—'),
              _InfoTile(label: 'Roles', value: user.roles.join(', ')),
              _InfoTile(label: 'Two-factor', value: user.twoFactorEnabled ? 'Enabled' : 'Disabled'),
            ],
          );
        },
      ),
    );
  }
}

class _InfoTile extends StatelessWidget {
  const _InfoTile({required this.label, required this.value, this.trailing});

  final String label;
  final String value;
  final String? trailing;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(label, style: const TextStyle(color: Colors.grey, fontSize: 13)),
      subtitle: Text(value, style: const TextStyle(fontSize: 16, color: Colors.black87)),
      trailing: trailing != null ? Text(trailing!) : null,
    );
  }
}
