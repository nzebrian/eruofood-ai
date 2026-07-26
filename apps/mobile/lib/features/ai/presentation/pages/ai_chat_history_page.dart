import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/ai_entities.dart';
import '../../domain/repositories/ai_repository.dart';

/// AI chat history: list past conversations and read a full thread.
class AiChatHistoryPage extends StatefulWidget {
  const AiChatHistoryPage({super.key});

  @override
  State<AiChatHistoryPage> createState() => _AiChatHistoryPageState();
}

class _AiChatHistoryPageState extends State<AiChatHistoryPage> {
  final AiRepository _repo = sl<AiRepository>();
  List<ConversationSummary> _conversations = <ConversationSummary>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.conversations();
    if (!mounted) {
      return;
    }
    setState(() {
      _conversations = result.getOrElse(() => <ConversationSummary>[]);
      _loading = false;
    });
  }

  Future<void> _open(String id) async {
    final result = await _repo.conversation(id);
    if (!mounted) {
      return;
    }
    result.fold(
      (_) {},
      (conversation) {
        Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => _ThreadPage(conversation: conversation)),
        );
      },
    );
  }

  Future<void> _delete(String id) async {
    await _repo.deleteConversation(id);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Chat history')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _conversations.isEmpty
              ? const Center(child: Text('No conversations yet.'))
              : ListView(
                  children: _conversations
                      .map(
                        (c) => ListTile(
                          title: Text(c.title),
                          subtitle: Text('${c.messageCount} messages'),
                          onTap: () => _open(c.id),
                          trailing: IconButton(
                            icon: const Icon(Icons.delete_outline),
                            onPressed: () => _delete(c.id),
                          ),
                        ),
                      )
                      .toList(),
                ),
    );
  }
}

class _ThreadPage extends StatelessWidget {
  const _ThreadPage({required this.conversation});

  final Conversation conversation;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(conversation.title)),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: conversation.messages
            .map(
              (m) => Align(
                alignment: m.isUser ? Alignment.centerRight : Alignment.centerLeft,
                child: Container(
                  margin: const EdgeInsets.symmetric(vertical: 4),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  constraints: const BoxConstraints(maxWidth: 300),
                  decoration: BoxDecoration(
                    color: m.isUser
                        ? Theme.of(context).colorScheme.primaryContainer
                        : Theme.of(context).colorScheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(m.content),
                ),
              ),
            )
            .toList(),
      ),
    );
  }
}
