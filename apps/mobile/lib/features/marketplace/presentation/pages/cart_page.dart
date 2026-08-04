import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/marketplace_entities.dart';
import '../../domain/repositories/marketplace_repository.dart';

/// Cart review + a minimal delivery checkout.
class CartPage extends StatefulWidget {
  const CartPage({super.key});

  @override
  State<CartPage> createState() => _CartPageState();
}

class _CartPageState extends State<CartPage> {
  final MarketplaceRepository _repo = sl<MarketplaceRepository>();
  CartView? _cart;
  bool _loading = true;
  bool _placing = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.cart();
    if (!mounted) {
      return;
    }
    setState(() {
      _cart = result.getOrElse(() => const CartView(currency: 'NGN', items: <CartLineView>[], subtotalMinor: 0));
      _loading = false;
    });
  }

  Future<void> _checkout() async {
    setState(() => _placing = true);
    final result = await _repo.checkout(<String, dynamic>{
      'fulfilment': 'delivery',
      'delivery_address': <String, dynamic>{'line': '1 Demo St', 'city': 'Lagos', 'state': 'Lagos'},
    });
    if (!mounted) {
      return;
    }
    setState(() => _placing = false);
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (order) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Order ${order.reference} placed!')));
        _load();
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final cart = _cart;
    return Scaffold(
      appBar: AppBar(title: const Text('Cart')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : cart == null || cart.isEmpty
              ? const Center(child: Text('Your cart is empty.'))
              : Column(
                  children: <Widget>[
                    Expanded(
                      child: ListView(
                        children: cart.items
                            .map(
                              (line) => ListTile(
                                title: Text('${line.quantity} × ${line.name}'),
                                trailing: Text(formatMoney(line.lineTotalMinor, cart.currency)),
                              ),
                            )
                            .toList(),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        children: <Widget>[
                          Text(
                            'Subtotal: ${formatMoney(cart.subtotalMinor, cart.currency)}',
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                          const SizedBox(height: 12),
                          SizedBox(
                            width: double.infinity,
                            child: FilledButton(
                              onPressed: _placing ? null : _checkout,
                              child: Text(_placing ? 'Placing…' : 'Checkout (delivery)'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
    );
  }
}
