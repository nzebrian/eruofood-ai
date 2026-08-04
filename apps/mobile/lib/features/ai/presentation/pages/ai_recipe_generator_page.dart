import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/ai_entities.dart';
import '../../domain/repositories/ai_repository.dart';

/// AI Recipe Generator: describe a dish and render the model's draft recipe.
class AiRecipeGeneratorPage extends StatefulWidget {
  const AiRecipeGeneratorPage({super.key});

  @override
  State<AiRecipeGeneratorPage> createState() => _AiRecipeGeneratorPageState();
}

class _AiRecipeGeneratorPageState extends State<AiRecipeGeneratorPage> {
  final AiRepository _repo = sl<AiRepository>();
  final TextEditingController _dish = TextEditingController();
  final TextEditingController _ingredients = TextEditingController();

  AiRecipeResult? _result;
  String? _error;
  bool _loading = false;

  List<String> _toList(String value) => value
      .split(',')
      .map((s) => s.trim())
      .where((s) => s.isNotEmpty)
      .toList();

  Future<void> _generate() async {
    if (_dish.text.trim().isEmpty) {
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
      _result = null;
    });

    final result = await _repo.generateRecipe(
      dishName: _dish.text.trim(),
      ingredients: _toList(_ingredients.text),
    );

    setState(() {
      _loading = false;
      result.fold(
        (failure) => _error = failure.message,
        (recipe) => _result = recipe,
      );
    });
  }

  @override
  void dispose() {
    _dish.dispose();
    _ingredients.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('AI Recipe Generator')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          TextField(
            controller: _dish,
            decoration: const InputDecoration(labelText: 'Dish name'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _ingredients,
            decoration: const InputDecoration(
              labelText: 'Ingredients you have (comma separated)',
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _loading ? null : _generate,
            child: Text(_loading ? 'Generating…' : 'Generate recipe'),
          ),
          if (_error != null) ...<Widget>[
            const SizedBox(height: 16),
            Text(_error!, style: const TextStyle(color: Colors.red)),
          ],
          if (_result != null) _RecipeView(result: _result!),
        ],
      ),
    );
  }
}

class _RecipeView extends StatelessWidget {
  const _RecipeView({required this.result});

  final AiRecipeResult result;

  @override
  Widget build(BuildContext context) {
    final recipe = result.recipe;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const SizedBox(height: 20),
        Text(recipe.title ?? 'Your recipe', style: Theme.of(context).textTheme.titleLarge),
        if (recipe.summary != null) ...<Widget>[
          const SizedBox(height: 6),
          Text(recipe.summary!),
        ],
        if (recipe.ingredients.isNotEmpty) ...<Widget>[
          const SizedBox(height: 12),
          Text('Ingredients', style: Theme.of(context).textTheme.titleMedium),
          ...recipe.ingredients.map((i) => Text('• $i')),
        ],
        if (recipe.steps.isNotEmpty) ...<Widget>[
          const SizedBox(height: 12),
          Text('Steps', style: Theme.of(context).textTheme.titleMedium),
          ...recipe.steps.asMap().entries.map((e) => Text('${e.key + 1}. ${e.value}')),
        ],
        const SizedBox(height: 16),
        Text(
          'Provider: ${result.meta.provider} · ${result.meta.cached ? 'cached' : 'fresh'} · '
          '${result.meta.totalTokens} tokens',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    );
  }
}
