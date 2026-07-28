import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/analytics_entities.dart';
import '../../domain/repositories/analytics_repository.dart';

/// A compact business-analytics summary (executive dashboard) for admins.
class AnalyticsSummaryPage extends StatefulWidget {
  const AnalyticsSummaryPage({super.key});

  @override
  State<AnalyticsSummaryPage> createState() => _AnalyticsSummaryPageState();
}

class _AnalyticsSummaryPageState extends State<AnalyticsSummaryPage> {
  final AnalyticsRepository _repo = sl<AnalyticsRepository>();
  DashboardView? _dashboard;
  String? _error;
  bool _loading = true;
  int _days = 30;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await _repo.dashboard('executive', _days);
    if (!mounted) return;
    setState(() {
      result.fold(
        (failure) {
          _error = failure.message;
          _dashboard = null;
        },
        (dashboard) {
          _dashboard = dashboard;
          _error = null;
        },
      );
      _loading = false;
    });
  }

  String _format(int value, String unit) {
    if (unit == 'money') {
      return '₦${(value / 100).toStringAsFixed(0)}';
    }
    return value.toString();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Analytics'),
        actions: <Widget>[
          PopupMenuButton<int>(
            initialValue: _days,
            onSelected: (v) {
              setState(() => _days = v);
              _load();
            },
            itemBuilder: (_) => const <PopupMenuEntry<int>>[
              PopupMenuItem<int>(value: 7, child: Text('Last 7 days')),
              PopupMenuItem<int>(value: 30, child: Text('Last 30 days')),
              PopupMenuItem<int>(value: 90, child: Text('Last 90 days')),
            ],
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : _dashboard == null
                  ? const Center(child: Text('No data.'))
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.all(12),
                        children: <Widget>[
                          GridView.count(
                            crossAxisCount: 2,
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            childAspectRatio: 1.6,
                            mainAxisSpacing: 10,
                            crossAxisSpacing: 10,
                            children: _dashboard!.kpis
                                .map(
                                  (k) => Card(
                                    child: Padding(
                                      padding: const EdgeInsets.all(12),
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        mainAxisAlignment: MainAxisAlignment.center,
                                        children: <Widget>[
                                          Text(k.label, style: const TextStyle(fontSize: 12, color: Colors.grey)),
                                          const SizedBox(height: 4),
                                          Text(
                                            _format(k.value, k.unit),
                                            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                                          ),
                                          if (k.deltaPct != null)
                                            Text(
                                              '${k.deltaPct! >= 0 ? '▲' : '▼'} ${k.deltaPct!.abs()}%',
                                              style: TextStyle(
                                                fontSize: 12,
                                                color: k.deltaPct! >= 0 ? Colors.green : Colors.red,
                                              ),
                                            ),
                                        ],
                                      ),
                                    ),
                                  ),
                                )
                                .toList(),
                          ),
                        ],
                      ),
                    ),
    );
  }
}
