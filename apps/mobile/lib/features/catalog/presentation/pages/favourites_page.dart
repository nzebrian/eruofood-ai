import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/recipe.dart';
import '../../domain/repositories/catalog_repository.dart';
import 'recipe_detail_page.dart';

class FavouritesPage extends StatefulWidget {
  const FavouritesPage({super.key});

  @override
  State<FavouritesPage> createState() => _FavouritesPageState();
}

class _FavouritesPageState extends State<FavouritesPage> {
  final CatalogRepository _repo = sl<CatalogRepository>();
  List<RecipeSummary> _recipes = <RecipeSummary>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.favourites();
    setState(() {
      _recipes = result.getOrElse(() => <RecipeSummary>[]);
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_recipes.isEmpty) {
      return const Center(child: Text('No favourite recipes yet.'));
    }
    return ListView(
      children: _recipes
          .map(
            (r) => ListTile(
              title: Text(r.title),
              subtitle: Text('${r.difficulty} · ${r.totalTimeMinutes} min'),
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute<void>(builder: (_) => RecipeDetailPage(slug: r.slug)),
              ),
            ),
          )
          .toList(),
    );
  }
}
