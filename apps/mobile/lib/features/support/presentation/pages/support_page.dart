import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/support_entities.dart';
import '../../domain/repositories/support_repository.dart';

/// The mobile support centre: raise and track tickets, live reply, and browse help.
class SupportPage extends StatefulWidget {
  const SupportPage({super.key});

  @override
  State<SupportPage> createState() => _SupportPageState();
}

class _SupportPageState extends State<SupportPage> with SingleTickerProviderStateMixin {
  final SupportRepository _repo = sl<SupportRepository>();
  late final TabController _tabs = TabController(length: 2, vsync: this);

  List<TicketSummaryView> _tickets = <TicketSummaryView>[];
  List<ArticleView> _articles = <ArticleView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final tickets = await _repo.myTickets();
    final articles = await _repo.articles('');
    if (!mounted) return;
    setState(() {
      tickets.fold((_) => _tickets = <TicketSummaryView>[], (t) => _tickets = t);
      articles.fold((_) => _articles = <ArticleView>[], (a) => _articles = a);
      _loading = false;
    });
  }

  Future<void> _openTicket(TicketSummaryView summary) async {
    final result = await _repo.ticket(summary.id);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (ticket) async {
        await Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => _TicketDetailPage(ticket: ticket, repo: _repo)),
        );
        await _load();
      },
    );
  }

  Future<void> _newTicket() async {
    final created = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _NewTicketSheet(repo: _repo),
    );
    if (created == true) {
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Support'),
        bottom: TabBar(controller: _tabs, tabs: const <Tab>[Tab(text: 'My tickets'), Tab(text: 'Help')]),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newTicket,
        icon: const Icon(Icons.add),
        label: const Text('New ticket'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tabs,
              children: <Widget>[_buildTickets(), _buildHelp()],
            ),
    );
  }

  Widget _buildTickets() {
    if (_tickets.isEmpty) {
      return const Center(child: Text('No tickets yet. Tap "New ticket" to get help.'));
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        itemCount: _tickets.length,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (context, index) {
          final t = _tickets[index];
          return ListTile(
            title: Text(t.subject),
            subtitle: Text('${t.ref} · ${t.status}'),
            trailing: t.sla.breached
                ? const Chip(label: Text('SLA'), backgroundColor: Color(0xFFFDECEC))
                : Chip(label: Text(t.priority)),
            onTap: () => _openTicket(t),
          );
        },
      ),
    );
  }

  Widget _buildHelp() {
    if (_articles.isEmpty) {
      return const Center(child: Text('No help articles yet.'));
    }
    return ListView.separated(
      itemCount: _articles.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final a = _articles[index];
        return ExpansionTile(
          title: Text(a.title),
          subtitle: Text(a.category),
          childrenPadding: const EdgeInsets.all(16),
          children: <Widget>[Align(alignment: Alignment.centerLeft, child: Text(a.body))],
        );
      },
    );
  }
}

class _NewTicketSheet extends StatefulWidget {
  const _NewTicketSheet({required this.repo});

  final SupportRepository repo;

  @override
  State<_NewTicketSheet> createState() => _NewTicketSheetState();
}

class _NewTicketSheetState extends State<_NewTicketSheet> {
  final TextEditingController _subject = TextEditingController();
  final TextEditingController _body = TextEditingController();
  String _category = 'general';
  String _priority = 'normal';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_subject.text.trim().isEmpty || _body.text.trim().isEmpty) return;
    setState(() => _busy = true);
    final result = await widget.repo.openTicket(_subject.text, _category, _body.text, _priority);
    if (!mounted) return;
    result.fold(
      (failure) => setState(() {
        _error = failure.message;
        _busy = false;
      }),
      (_) => Navigator.of(context).pop(true),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('New ticket', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 12),
          TextField(controller: _subject, decoration: const InputDecoration(labelText: 'Subject', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Expanded(
                child: DropdownButtonFormField<String>(
                  value: _category,
                  decoration: const InputDecoration(labelText: 'Category', border: OutlineInputBorder()),
                  items: const <String>['general', 'billing', 'orders', 'account']
                      .map((c) => DropdownMenuItem<String>(value: c, child: Text(c)))
                      .toList(),
                  onChanged: (v) => setState(() => _category = v ?? 'general'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: DropdownButtonFormField<String>(
                  value: _priority,
                  decoration: const InputDecoration(labelText: 'Priority', border: OutlineInputBorder()),
                  items: const <String>['low', 'normal', 'high', 'urgent']
                      .map((p) => DropdownMenuItem<String>(value: p, child: Text(p)))
                      .toList(),
                  onChanged: (v) => setState(() => _priority = v ?? 'normal'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _body,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Describe your issue', border: OutlineInputBorder()),
          ),
          if (_error != null) ...<Widget>[
            const SizedBox(height: 8),
            Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton(onPressed: _busy ? null : _submit, child: const Text('Submit')),
          ),
        ],
      ),
    );
  }
}

class _TicketDetailPage extends StatefulWidget {
  const _TicketDetailPage({required this.ticket, required this.repo});

  final TicketView ticket;
  final SupportRepository repo;

  @override
  State<_TicketDetailPage> createState() => _TicketDetailPageState();
}

class _TicketDetailPageState extends State<_TicketDetailPage> {
  final TextEditingController _reply = TextEditingController();
  late TicketView _ticket = widget.ticket;
  bool _busy = false;

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_reply.text.trim().isEmpty) return;
    setState(() => _busy = true);
    final result = await widget.repo.reply(_ticket.id, _reply.text.trim());
    if (!mounted) return;
    setState(() {
      result.fold((_) => null, (t) {
        _ticket = t;
        _reply.clear();
      });
      _busy = false;
    });
  }

  Future<void> _rate(int score) async {
    final result = await widget.repo.submitCsat(_ticket.id, score, null);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (_) => ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Thanks for your feedback!'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    final visible = _ticket.messages.where((m) => !m.internal).toList();
    final terminal = _ticket.status == 'resolved' || _ticket.status == 'closed';

    return Scaffold(
      appBar: AppBar(title: Text(_ticket.ref)),
      body: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: <Widget>[
                Expanded(child: Text(_ticket.subject, style: Theme.of(context).textTheme.titleMedium)),
                Chip(label: Text(_ticket.status)),
              ],
            ),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: <Widget>[
                ...visible.map((m) => Card(
                      color: m.authorType == 'customer' ? Theme.of(context).colorScheme.surfaceContainerHighest : null,
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: <Widget>[
                            Text(m.authorType, style: Theme.of(context).textTheme.labelSmall),
                            const SizedBox(height: 4),
                            Text(m.body),
                          ],
                        ),
                      ),
                    )),
                if (terminal && _ticket.csatScore == null) ...<Widget>[
                  const SizedBox(height: 8),
                  const Text('How did we do?'),
                  Row(
                    children: List<Widget>.generate(
                      5,
                      (i) => IconButton(icon: const Icon(Icons.star_border), onPressed: () => _rate(i + 1)),
                    ),
                  ),
                ],
              ],
            ),
          ),
          if (_ticket.status != 'closed')
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
                    IconButton(icon: const Icon(Icons.send), onPressed: _busy ? null : _send),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
