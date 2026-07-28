import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/admin_entities.dart';
import '../../domain/repositories/admin_repository.dart';

/// Support management: the ticket queue with reply and resolve actions.
class SupportTicketsPage extends StatefulWidget {
  const SupportTicketsPage({super.key});

  @override
  State<SupportTicketsPage> createState() => _SupportTicketsPageState();
}

class _SupportTicketsPageState extends State<SupportTicketsPage> {
  final AdminRepository _repo = sl<AdminRepository>();
  List<TicketView> _tickets = <TicketView>[];
  String _status = 'open';
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await _repo.tickets(_status);
    if (!mounted) return;
    setState(() {
      result.fold(
        (failure) {
          _error = failure.message;
          _tickets = <TicketView>[];
        },
        (tickets) {
          _tickets = tickets;
          _error = null;
        },
      );
      _loading = false;
    });
  }

  Future<void> _openTicket(TicketView ticket) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => _TicketDetailPage(ticket: ticket, repo: _repo)),
    );
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Support'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(48),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: <String>['open', 'pending', 'resolved', 'closed'].map((status) {
                final selected = status == _status;
                return Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(
                    label: Text(status),
                    selected: selected,
                    onSelected: (_) {
                      setState(() => _status = status);
                      _load();
                    },
                  ),
                );
              }).toList(),
            ),
          ),
        ),
      ),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return ListView(children: <Widget>[const SizedBox(height: 80), Center(child: Text(_error!))]);
    }
    if (_tickets.isEmpty) {
      return ListView(
        children: const <Widget>[SizedBox(height: 80), Center(child: Text('No tickets.'))],
      );
    }
    return ListView.separated(
      itemCount: _tickets.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final ticket = _tickets[index];
        return ListTile(
          title: Text(ticket.subject),
          subtitle: Text('${ticket.category} · ${ticket.status}'),
          trailing: Chip(label: Text(ticket.priority)),
          onTap: () => _openTicket(ticket),
        );
      },
    );
  }
}

class _TicketDetailPage extends StatefulWidget {
  const _TicketDetailPage({required this.ticket, required this.repo});

  final TicketView ticket;
  final AdminRepository repo;

  @override
  State<_TicketDetailPage> createState() => _TicketDetailPageState();
}

class _TicketDetailPageState extends State<_TicketDetailPage> {
  final TextEditingController _reply = TextEditingController();
  late TicketView _ticket = widget.ticket;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_reply.text.trim().isEmpty) return;
    setState(() => _busy = true);
    final result = await widget.repo.replyTicket(_ticket.id, _reply.text.trim());
    if (!mounted) return;
    setState(() {
      result.fold((failure) => _error = failure.message, (ticket) {
        _ticket = ticket;
        _reply.clear();
        _error = null;
      });
      _busy = false;
    });
  }

  Future<void> _resolve() async {
    setState(() => _busy = true);
    final result = await widget.repo.resolveTicket(_ticket.id);
    if (!mounted) return;
    setState(() {
      result.fold((failure) => _error = failure.message, (ticket) => _ticket = ticket);
      _busy = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_ticket.subject),
        actions: <Widget>[
          TextButton(
            onPressed: _busy ? null : _resolve,
            child: const Text('Resolve', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
      body: Column(
        children: <Widget>[
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: <Widget>[
                Text('${_ticket.category} · ${_ticket.status}',
                    style: Theme.of(context).textTheme.bodySmall),
                const SizedBox(height: 12),
                ..._ticket.messages.map((message) => Card(
                      color: message.internal
                          ? Theme.of(context).colorScheme.surfaceContainerHighest
                          : null,
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text(
                              message.internal ? 'Internal note' : message.authorId,
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                            const SizedBox(height: 4),
                            Text(message.body),
                          ],
                        ),
                      ),
                    )),
                if (_error != null) ...<Widget>[
                  const SizedBox(height: 12),
                  Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                ],
              ],
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: TextField(
                      controller: _reply,
                      decoration: const InputDecoration(hintText: 'Write a reply…'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton(
                    icon: const Icon(Icons.send),
                    onPressed: _busy ? null : _send,
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
