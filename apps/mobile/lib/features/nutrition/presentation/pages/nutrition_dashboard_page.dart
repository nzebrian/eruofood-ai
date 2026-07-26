import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/nutrition_entities.dart';
import '../../domain/repositories/nutrition_repository.dart';

/// Nutrition dashboard: today's targets and intake.
class NutritionDashboardPage extends StatefulWidget {
  const NutritionDashboardPage({super.key});

  @override
  State<NutritionDashboardPage> createState() => _NutritionDashboardPageState();
}

class _NutritionDashboardPageState extends State<NutritionDashboardPage> {
  final NutritionRepository _repo = sl<NutritionRepository>();
  Assessment? _assessment;
  DailySummary? _summary;
  bool _loading = true;
  bool _needsProfile = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final today = DateTime.now().toIso8601String().substring(0, 10);
    final assessment = await _repo.assessment();
    final summary = await _repo.diaryDay(today);
    if (!mounted) {
      return;
    }
    setState(() {
      assessment.fold((_) => _needsProfile = true, (a) => _assessment = a);
      summary.fold((_) {}, (s) => _summary = s);
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_needsProfile) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('Set up your health profile to see your targets.'),
        ),
      );
    }

    final a = _assessment;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: <Widget>[
        if (a != null) ...<Widget>[
          _StatCard(label: 'BMI', value: '${a.bmi} (${a.bmiCategory})'),
          _StatCard(label: 'Calorie target', value: '${a.calorieTarget} kcal'),
          _StatCard(label: 'BMR / TDEE', value: '${a.bmr} / ${a.tdee}'),
          _StatCard(
            label: 'Macros (P/C/F)',
            value: '${a.proteinGrams} / ${a.carbGrams} / ${a.fatGrams} g',
          ),
        ],
        const SizedBox(height: 8),
        if (_summary != null)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text('Today', style: Theme.of(context).textTheme.titleMedium),
                  Text('Eaten: ${_summary!.totalCalories.round()} kcal'),
                  if (_summary!.remainingCalories != null)
                    Text('Remaining: ${_summary!.remainingCalories} kcal'),
                  const SizedBox(height: 8),
                  ..._summary!.items.map(
                    (i) => Text('• ${i.mealType}: ${i.itemName} (${i.calories.round()} kcal)'),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        title: Text(label),
        trailing: Text(value, style: Theme.of(context).textTheme.titleMedium),
      ),
    );
  }
}
