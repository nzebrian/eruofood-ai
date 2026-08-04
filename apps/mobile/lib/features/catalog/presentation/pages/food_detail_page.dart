import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/food.dart';
import '../../domain/entities/recipe.dart';
import '../../domain/repositories/catalog_repository.dart';
import 'recipe_detail_page.dart';

class FoodDetailPage extends StatefulWidget {
  const FoodDetailPage({required this.slug, super.key});

  final String slug;

  @override
  State<FoodDetailPage> createState() => _FoodDetailPageState();
}

class _FoodDetailPageState extends State<FoodDetailPage> {
  final CatalogRepository _repo = sl<CatalogRepository>();
  Food? _food;
  List<RecipeSummary> _recipes = <RecipeSummary>[];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final foodResult = await _repo.food(widget.slug);
    await foodResult.fold(
      (failure) async => setState(() => _error = failure.message),
      (food) async {
        final recipes = await _repo.recipesForFood(food.id);
        setState(() {
          _food = food;
          _recipes = recipes.getOrElse(() => <RecipeSummary>[]);
        });
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final food = _food;
    return Scaffold(
      appBar: AppBar(title: Text(food?.name ?? 'Food')),
      body: _error != null
          ? Center(child: Text(_error!))
          : food == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: <Widget>[
                    if (food.localNames.isNotEmpty)
                      Text(
                        'Also known as: ${food.localNames.map((l) => '${l.name} (${l.language})').join(', ')}',
                        style: const TextStyle(color: Colors.grey),
                      ),
                    const SizedBox(height: 8),
                    Text(food.region.replaceAll('_', ' '), style: const TextStyle(color: Colors.grey)),
                    if (food.description != null) ...<Widget>[
                      const SizedBox(height: 12),
                      Text(food.description!),
                    ],
                    const SizedBox(height: 20),
                    Text('Recipes', style: Theme.of(context).textTheme.titleLarge),
                    if (_recipes.isEmpty)
                      const Padding(padding: EdgeInsets.only(top: 8), child: Text('No recipes yet.')),
                    ..._recipes.map(
                      (r) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(r.title),
                        subtitle: Text('${r.difficulty} · ${r.totalTimeMinutes} min · ★ ${r.ratingAverage}'),
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(builder: (_) => RecipeDetailPage(slug: r.slug)),
                        ),
                      ),
                    ),
                  ],
                ),
    );
  }
}
