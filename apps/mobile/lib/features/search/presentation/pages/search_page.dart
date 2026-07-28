import 'dart:async';

import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/search_entities.dart';
import '../../domain/repositories/search_repository.dart';

/// Global search & discovery: a query box with autocomplete, a filter sheet,
/// ranked results and a recommendations strip on the empty state.
class SearchPage extends StatefulWidget {
  const SearchPage({super.key});

  @override
  State<SearchPage> createState() => _SearchPageState();
}

class _SearchPageState extends State<SearchPage> {
  final SearchRepository _repo = sl<SearchRepository>();
  final TextEditingController _controller = TextEditingController();

  String _type = 'global';
  String _sort = 'relevance';
  SearchFiltersView _filters = const SearchFiltersView();

  SearchResultsView? _results;
  List<String> _suggestions = <String>[];
  List<SearchDocumentView> _recommended = <SearchDocumentView>[];
  bool _loading = false;
  String? _error;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _loadRecommendations();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _loadRecommendations() async {
    final result = await _repo.recommendations('trending', 'food');
    if (!mounted) return;
    result.fold((_) => null, (items) => setState(() => _recommended = items));
  }

  void _onChanged(String value) {
    _debounce?.cancel();
    if (value.trim().isEmpty) {
      setState(() => _suggestions = <String>[]);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 200), () async {
      final result = await _repo.autocomplete(value, _type);
      if (!mounted) return;
      result.fold((_) => null, (s) => setState(() => _suggestions = s));
    });
  }

  Future<void> _runSearch(String term) async {
    setState(() {
      _loading = true;
      _suggestions = <String>[];
    });
    final result = await _repo.search(term, _type, _sort, _filters);
    if (!mounted) return;
    setState(() {
      result.fold(
        (failure) {
          _error = failure.message;
          _results = null;
        },
        (results) {
          _results = results;
          _error = null;
        },
      );
      _loading = false;
    });
  }

  Future<void> _openFilters() async {
    final updated = await showModalBottomSheet<SearchFiltersView>(
      context: context,
      builder: (_) => _FilterSheet(sort: _sort, filters: _filters),
    );
    if (updated != null) {
      setState(() => _filters = updated);
      if (_controller.text.trim().isNotEmpty) {
        await _runSearch(_controller.text);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Search'),
        actions: <Widget>[
          IconButton(icon: const Icon(Icons.tune), tooltip: 'Filters', onPressed: _openFilters),
        ],
      ),
      body: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _controller,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Search foods, recipes, restaurants…',
                prefixIcon: const Icon(Icons.search),
                border: const OutlineInputBorder(),
                suffixIcon: DropdownButton<String>(
                  value: _type,
                  underline: const SizedBox.shrink(),
                  items: const <String>['global', 'food', 'recipe', 'restaurant', 'vendor', 'product']
                      .map((t) => DropdownMenuItem<String>(value: t, child: Text(t)))
                      .toList(),
                  onChanged: (v) => setState(() => _type = v ?? 'global'),
                ),
              ),
              onChanged: _onChanged,
              onSubmitted: _runSearch,
            ),
          ),
          if (_suggestions.isNotEmpty)
            SizedBox(
              height: 40,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                children: _suggestions
                    .map((s) => Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: ActionChip(
                            label: Text(s),
                            onPressed: () {
                              _controller.text = s;
                              _runSearch(s);
                            },
                          ),
                        ))
                    .toList(),
              ),
            ),
          Expanded(child: _buildBody()),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(child: Text(_error!));
    }
    if (_results == null) {
      return _buildRecommendations();
    }
    if (_results!.hits.isEmpty) {
      return const Center(child: Text('No matches. Try broadening your filters.'));
    }
    return ListView.separated(
      itemCount: _results!.hits.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final hit = _results!.hits[index];
        return ListTile(
          title: Text(hit.document.title),
          subtitle: Text(hit.highlight ?? hit.document.description, maxLines: 2, overflow: TextOverflow.ellipsis),
          leading: Chip(label: Text(hit.document.type)),
          trailing: hit.document.rating > 0 ? Text('★ ${hit.document.rating.toStringAsFixed(1)}') : null,
        );
      },
    );
  }

  Widget _buildRecommendations() {
    if (_recommended.isEmpty) {
      return const Center(child: Text('Search across foods, recipes, restaurants and products.'));
    }
    return ListView(
      padding: const EdgeInsets.all(12),
      children: <Widget>[
        Text('Popular right now', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        ..._recommended.map((doc) => Card(
              child: ListTile(
                title: Text(doc.title),
                subtitle: Text(doc.type),
                trailing: doc.rating > 0 ? Text('★ ${doc.rating.toStringAsFixed(1)}') : null,
              ),
            )),
      ],
    );
  }
}

class _FilterSheet extends StatefulWidget {
  const _FilterSheet({required this.sort, required this.filters});

  final String sort;
  final SearchFiltersView filters;

  @override
  State<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<_FilterSheet> {
  late final TextEditingController _region = TextEditingController(text: widget.filters.region);
  String? _difficulty;
  double _minRating = 0;

  @override
  void initState() {
    super.initState();
    _difficulty = widget.filters.difficulty;
    _minRating = widget.filters.minRating ?? 0;
  }

  @override
  void dispose() {
    _region.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('Filters', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 12),
          TextField(
            controller: _region,
            decoration: const InputDecoration(labelText: 'Region', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _difficulty,
            decoration: const InputDecoration(labelText: 'Difficulty', border: OutlineInputBorder()),
            items: const <String>['easy', 'medium', 'hard']
                .map((d) => DropdownMenuItem<String>(value: d, child: Text(d)))
                .toList(),
            onChanged: (v) => setState(() => _difficulty = v),
          ),
          const SizedBox(height: 12),
          Text('Minimum rating: ${_minRating.toStringAsFixed(1)}'),
          Slider(
            value: _minRating,
            max: 5,
            divisions: 10,
            label: _minRating.toStringAsFixed(1),
            onChanged: (v) => setState(() => _minRating = v),
          ),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: () => Navigator.of(context).pop(SearchFiltersView(
                region: _region.text,
                difficulty: _difficulty,
                minRating: _minRating > 0 ? _minRating : null,
              )),
              child: const Text('Apply filters'),
            ),
          ),
        ],
      ),
    );
  }
}
