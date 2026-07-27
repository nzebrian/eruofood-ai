import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/notifications_entities.dart';
import '../../domain/repositories/notifications_repository.dart';
import 'chat_page.dart';

/// The messaging inbox.
class ConversationsPage extends StatefulWidget {
  const ConversationsPage({super.key});

  @override
  State<ConversationsPage> createState() => _ConversationsPageState();
}

class _ConversationsPageState extends State<ConversationsPage> {
  final NotificationsRepository _repo = sl<NotificationsRepository>();
  List<ConversationView> _items = <ConversationView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.conversations();
    if (!mounted) return;
    setState(() {
      _items = result.getOrElse(() => <ConversationView>[]);
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _items.isEmpty
              ? const Center(child: Text('No conversations yet.'))
              : ListView(
                  children: _items
                      .map(
                        (c) => ListTile(
                          leading: const Icon(Icons.forum_outlined),
                          title: Text(c.subject ?? c.type.replaceAll('_', ' ')),
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => ChatPage(conversationId: c.id, title: c.subject ?? 'Chat'),
                            ),
                          ),
                        ),
                      )
                      .toList(),
                ),
    );
  }
}
