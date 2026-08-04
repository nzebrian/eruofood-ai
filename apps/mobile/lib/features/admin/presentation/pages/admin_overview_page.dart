import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/admin_entities.dart';
import '../../domain/repositories/admin_repository.dart';
import 'support_tickets_page.dart';

/// Admin overview: recent platform activity from the audit trail, with a
/// shortcut into support management.
class AdminOverviewPage extends StatefulWidget {
  const AdminOverviewPage({super.key});

  @override
  State<AdminOverviewPage> createState() => _AdminOverviewPageState();
}

class _AdminOverviewPageState extends State<AdminOverviewPage> {
  final AdminRepository _repo = sl<AdminRepository>();
  List<AuditEntryView> _audit = <AuditEntryView>[];
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await _repo.recentAudit();
    if (!mounted) return;
    setState(() {
      result.fold(
        (failure) {
          _error = failure.message;
          _audit = <AuditEntryView>[];
        },
        (entries) {
          _audit = entries;
          _error = null;
        },
      );
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Administration'),
        actions: <Widget>[
          IconButton(
            icon: const Icon(Icons.support_agent),
            tooltip: 'Support',
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const SupportTicketsPage()),
            ),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _buildBody(context),
      ),
    );
  }

  Widget _buildBody(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return ListView(
        children: <Widget>[
          const SizedBox(height: 80),
          Center(child: Text(_error!)),
        ],
      );
    }
    if (_audit.isEmpty) {
      return ListView(
        children: const <Widget>[
          SizedBox(height: 80),
          Center(child: Text('No recent activity.')),
        ],
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _audit.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final entry = _audit[index];
        return ListTile(
          leading: CircleAvatar(child: Text(entry.category.substring(0, 1).toUpperCase())),
          title: Text(entry.action),
          subtitle: Text('${entry.category} · ${entry.subjectId ?? 'system'}'),
          trailing: Text(
            entry.createdAt.length >= 10 ? entry.createdAt.substring(0, 10) : entry.createdAt,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        );
      },
    );
  }
}
