import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/commerce_entities.dart';
import '../../domain/repositories/commerce_repository.dart';
import 'commerce_cart_page.dart';

/// Marketplace & Grocery: browse products, filter by department, add to cart.
class ShopPage extends StatefulWidget {
  const ShopPage({super.key});

  @override
  State<ShopPage> createState() => _ShopPageState();
}

class _ShopPageState extends State<ShopPage> {
  static const List<String> _departments = <String>[
    'produce',
    'pantry',
    'beverages',
    'frozen',
    'household',
  ];

  final CommerceRepository _repo = sl<CommerceRepository>();
  final TextEditingController _search = TextEditingController();
  List<ProductSummary> _products = <ProductSummary>[];
  String? _department;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await _repo.products(query: _search.text, department: _department);
    if (!mounted) return;
    setState(() {
      _products = result.getOrElse(() => <ProductSummary>[]);
      _loading = false;
    });
  }

  Future<void> _addToCart(ProductSummary product) async {
    final result = await _repo.addToCart(product.id, 1);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (_) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Added ${product.name} to cart.')),
      ),
    );
  }

  String _money(int minor, String currency) {
    final symbol = currency == 'NGN' ? '₦' : '$currency ';
    return '$symbol${(minor / 100).toStringAsFixed(2)}';
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Shop & Grocery'),
        actions: <Widget>[
          IconButton(
            icon: const Icon(Icons.shopping_cart_outlined),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const CommerceCartPage()),
            ),
          ),
        ],
      ),
      body: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _search,
              decoration: const InputDecoration(hintText: 'Search products…', prefixIcon: Icon(Icons.search)),
              onSubmitted: (_) => _load(),
            ),
          ),
          SizedBox(
            height: 44,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 8),
              children: <Widget>[
                _deptChip('All', null),
                ..._departments.map((d) => _deptChip(d[0].toUpperCase() + d.substring(1), d)),
              ],
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _products.isEmpty
                    ? const Center(child: Text('No products found.'))
                    : ListView(
                        children: _products
                            .map(
                              (p) => ListTile(
                                leading: Icon(p.kind == 'grocery' ? Icons.local_grocery_store : Icons.inventory_2),
                                title: Text(p.name),
                                subtitle: Text(
                                  _money(p.basePriceMinor, p.currency) +
                                      (p.ratingCount > 0 ? ' · ⭐ ${p.ratingAverage}' : ''),
                                ),
                                trailing: IconButton(
                                  icon: const Icon(Icons.add_shopping_cart),
                                  onPressed: () => _addToCart(p),
                                ),
                              ),
                            )
                            .toList(),
                      ),
          ),
        ],
      ),
    );
  }

  Widget _deptChip(String label, String? value) {
    final selected = _department == value;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) {
          setState(() => _department = value);
          _load();
        },
      ),
    );
  }
}
