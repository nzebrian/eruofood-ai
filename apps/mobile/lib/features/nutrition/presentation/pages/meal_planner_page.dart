import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/nutrition_entities.dart';
import '../../domain/repositories/nutrition_repository.dart';

/// Meal planner: list plans and fetch AI meal ideas.
class MealPlannerPage extends StatefulWidget {
  const MealPlannerPage({super.key});

  @override
  State<MealPlannerPage> createState() => _MealPlannerPageState();
}

class _MealPlannerPageState extends State<MealPlannerPage> {
  final NutritionRepository _repo = sl<NutritionRepository>();
  List<MealPlanView> _plans = <MealPlanView>[];
  NutritionAdviceView? _advice;
  bool _loading = true;
  bool _loadingAdvice = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.mealPlans();
    if (!mounted) {
      return;
    }
    setState(() {
      _plans = result.getOrElse(() => <MealPlanView>[]);
      _loading = false;
    });
  }

  Future<void> _ideas() async {
    setState(() => _loadingAdvice = true);
    final result = await _repo.mealRecommendations();
    if (!mounted) {
      return;
    }
    setState(() {
      _loadingAdvice = false;
      result.fold((_) {}, (a) => _advice = a);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Meal planner')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: <Widget>[
                if (_plans.isEmpty)
                  const Text('No meal plans yet.')
                else
                  ..._plans.map(
                    (p) => Card(
                      child: ListTile(
                        title: Text(p.title),
                        subtitle: Text('${p.period} · ${p.mealCount} meals'),
                        trailing: p.estimatedCost > 0 ? Text('₦${p.estimatedCost.toStringAsFixed(0)}') : null,
                      ),
                    ),
                  ),
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: _loadingAdvice ? null : _ideas,
                  icon: const Icon(Icons.auto_awesome),
                  label: Text(_loadingAdvice ? 'Thinking…' : 'AI meal ideas'),
                ),
                if (_advice != null) ...<Widget>[
                  const SizedBox(height: 12),
                  Text(_advice!.advice),
                ],
              ],
            ),
    );
  }
}
