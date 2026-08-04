import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/recipe.dart';
import '../../domain/repositories/catalog_repository.dart';

class RecipeDetailPage extends StatefulWidget {
  const RecipeDetailPage({required this.slug, super.key});

  final String slug;

  @override
  State<RecipeDetailPage> createState() => _RecipeDetailPageState();
}

class _RecipeDetailPageState extends State<RecipeDetailPage> {
  final CatalogRepository _repo = sl<CatalogRepository>();
  Recipe? _recipe;
  bool _favourited = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.recipe(widget.slug);
    result.fold(
      (failure) => setState(() => _error = failure.message),
      (recipe) => setState(() {
        _recipe = recipe;
        _favourited = recipe.isFavourited;
      }),
    );
  }

  Future<void> _toggleFavourite() async {
    final recipe = _recipe;
    if (recipe == null) return;
    if (_favourited) {
      await _repo.removeFavourite(recipe.id);
    } else {
      await _repo.addFavourite(recipe.id);
    }
    setState(() => _favourited = !_favourited);
  }

  @override
  Widget build(BuildContext context) {
    final recipe = _recipe;
    return Scaffold(
      appBar: AppBar(
        title: Text(recipe?.title ?? 'Recipe'),
        actions: <Widget>[
          if (recipe != null)
            IconButton(
              icon: Icon(_favourited ? Icons.favorite : Icons.favorite_border),
              onPressed: _toggleFavourite,
            ),
        ],
      ),
      body: _error != null
          ? Center(child: Text(_error!))
          : recipe == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: <Widget>[
                    Text(
                      '${recipe.difficulty} · ${recipe.totalTimeMinutes} min · serves ${recipe.servingSize} · ★ ${recipe.ratingAverage} (${recipe.ratingCount})',
                      style: const TextStyle(color: Colors.grey),
                    ),
                    if (recipe.summary != null) ...<Widget>[
                      const SizedBox(height: 12),
                      Text(recipe.summary!),
                    ],
                    const SizedBox(height: 20),
                    Text('Ingredients', style: Theme.of(context).textTheme.titleLarge),
                    ...recipe.ingredients.map(
                      (i) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        leading: const Icon(Icons.circle, size: 8),
                        title: Text('${i.amount} ${i.unit} ${i.name}'),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text('Steps', style: Theme.of(context).textTheme.titleLarge),
                    ...recipe.steps.map(
                      (s) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: CircleAvatar(radius: 12, child: Text('${s.order}')),
                        title: Text(s.instruction),
                      ),
                    ),
                    if (recipe.tips.isNotEmpty) ...<Widget>[
                      const SizedBox(height: 12),
                      Text('Tips', style: Theme.of(context).textTheme.titleLarge),
                      ...recipe.tips.map((t) => Text('• $t')),
                    ],
                  ],
                ),
    );
  }
}
