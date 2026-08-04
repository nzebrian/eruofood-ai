import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/marketplace_entities.dart';
import '../../domain/repositories/marketplace_repository.dart';

/// A vendor's storefront: menu with add-to-cart.
class VendorStorefrontPage extends StatefulWidget {
  const VendorStorefrontPage({required this.vendorId, required this.vendorName, super.key});

  final String vendorId;
  final String vendorName;

  @override
  State<VendorStorefrontPage> createState() => _VendorStorefrontPageState();
}

class _VendorStorefrontPageState extends State<VendorStorefrontPage> {
  final MarketplaceRepository _repo = sl<MarketplaceRepository>();
  List<MenuItemView> _menu = <MenuItemView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.menu(widget.vendorId);
    if (!mounted) {
      return;
    }
    setState(() {
      _menu = result.getOrElse(() => <MenuItemView>[]);
      _loading = false;
    });
  }

  Future<void> _add(MenuItemView item) async {
    final result = await _repo.addToCart(item.id, 1);
    if (!mounted) {
      return;
    }
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (_) => ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Added ${item.name} to cart.'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.vendorName)),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _menu.isEmpty
              ? const Center(child: Text('No menu items yet.'))
              : ListView(
                  children: _menu
                      .map(
                        (item) => ListTile(
                          title: Text(item.name),
                          subtitle: item.description != null ? Text(item.description!) : null,
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: <Widget>[
                              Text(formatMoney(item.basePriceMinor, item.currency)),
                              const SizedBox(width: 8),
                              IconButton(
                                icon: const Icon(Icons.add_shopping_cart),
                                onPressed: item.orderable ? () => _add(item) : null,
                              ),
                            ],
                          ),
                        ),
                      )
                      .toList(),
                ),
    );
  }
}
