import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/nutrition_entities.dart';
import '../../domain/repositories/nutrition_repository.dart';

/// Progress tracker: record weight, view history, get weekly AI insights.
class ProgressTrackerPage extends StatefulWidget {
  const ProgressTrackerPage({super.key});

  @override
  State<ProgressTrackerPage> createState() => _ProgressTrackerPageState();
}

class _ProgressTrackerPageState extends State<ProgressTrackerPage> {
  final NutritionRepository _repo = sl<NutritionRepository>();
  final TextEditingController _weight = TextEditingController(text: '80');
  List<ProgressPoint> _history = <ProgressPoint>[];
  NutritionAdviceView? _insight;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.progress();
    if (!mounted) {
      return;
    }
    setState(() {
      _history = result.getOrElse(() => <ProgressPoint>[]);
      _loading = false;
    });
  }

  Future<void> _record() async {
    final today = DateTime.now().toIso8601String().substring(0, 10);
    await _repo.recordProgress(<String, dynamic>{
      'date': today,
      'weight_kg': double.tryParse(_weight.text) ?? 0,
    });
    await _load();
  }

  Future<void> _insights() async {
    final result = await _repo.weeklyInsights();
    if (!mounted) {
      return;
    }
    setState(() => result.fold((_) {}, (a) => _insight = a));
  }

  @override
  void dispose() {
    _weight.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Progress')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: TextField(
                  controller: _weight,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: "Today's weight (kg)"),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(onPressed: _record, child: const Text('Log')),
            ],
          ),
          const SizedBox(height: 16),
          if (_loading)
            const Center(child: CircularProgressIndicator())
          else if (_history.isEmpty)
            const Text('No measurements yet.')
          else
            ..._history.map(
              (p) => ListTile(
                dense: true,
                title: Text('${p.weightKg} kg'),
                subtitle: Text(p.date),
              ),
            ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: _insights,
            icon: const Icon(Icons.insights),
            label: const Text('Weekly AI insights'),
          ),
          if (_insight != null) ...<Widget>[
            const SizedBox(height: 12),
            Text(_insight!.advice),
          ],
        ],
      ),
    );
  }
}
