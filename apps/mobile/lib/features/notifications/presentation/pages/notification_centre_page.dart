import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/notifications_entities.dart';
import '../../domain/repositories/notifications_repository.dart';
import 'conversations_page.dart';

/// The in-app notification centre.
class NotificationCentrePage extends StatefulWidget {
  const NotificationCentrePage({super.key});

  @override
  State<NotificationCentrePage> createState() => _NotificationCentrePageState();
}

class _NotificationCentrePageState extends State<NotificationCentrePage> {
  final NotificationsRepository _repo = sl<NotificationsRepository>();
  List<AppNotification> _items = <AppNotification>[];
  bool _loading = true;

  static const Map<String, IconData> _icons = <String, IconData>{
    'account': Icons.person_outline,
    'order': Icons.shopping_bag_outlined,
    'payment': Icons.payment,
    'wallet': Icons.account_balance_wallet_outlined,
    'delivery': Icons.delivery_dining,
    'promotional': Icons.campaign_outlined,
    'ai': Icons.auto_awesome,
    'nutrition': Icons.restaurant_outlined,
    'admin': Icons.notifications_outlined,
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await _repo.notifications();
    if (!mounted) return;
    setState(() {
      _items = result.getOrElse(() => <AppNotification>[]);
      _loading = false;
    });
  }

  Future<void> _markRead(AppNotification n) async {
    await _repo.markRead(n.id);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: <Widget>[
          IconButton(
            icon: const Icon(Icons.chat_bubble_outline),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const ConversationsPage()),
            ),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _items.isEmpty
              ? const Center(child: Text("You're all caught up."))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    children: _items
                        .map(
                          (n) => ListTile(
                            leading: Icon(_icons[n.category] ?? Icons.notifications),
                            title: Text(n.subject),
                            subtitle: Text(n.body),
                            trailing: n.read
                                ? null
                                : const Icon(Icons.circle, size: 10, color: Colors.green),
                            onTap: n.read ? null : () => _markRead(n),
                          ),
                        )
                        .toList(),
                  ),
                ),
    );
  }
}
