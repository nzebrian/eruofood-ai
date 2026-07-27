import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/commerce_entities.dart';
import '../../domain/repositories/commerce_repository.dart';

/// Cart review, coupon entry and a minimal pickup/delivery checkout.
class CommerceCartPage extends StatefulWidget {
  const CommerceCartPage({super.key});

  @override
  State<CommerceCartPage> createState() => _CommerceCartPageState();
}

class _CommerceCartPageState extends State<CommerceCartPage> {
  final CommerceRepository _repo = sl<CommerceRepository>();
  final TextEditingController _coupon = TextEditingController();
  CartView? _cart;
  bool _loading = true;
  bool _pickup = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await _repo.cart();
    if (!mounted) return;
    setState(() {
      _cart = result.fold((_) => null, (cart) => cart);
      _coupon.text = _cart?.couponCode ?? '';
      _loading = false;
    });
  }

  Future<void> _applyCoupon() async {
    final result = await _repo.applyCoupon(_coupon.text);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (cart) => setState(() => _cart = cart),
    );
  }

  Future<void> _checkout() async {
    final payload = <String, dynamic>{
      'pickup': _pickup,
      if (!_pickup)
        'shipping_address': <String, dynamic>{'line1': '1 Demo St', 'city': 'Lagos', 'state': 'Lagos'},
    };
    final result = await _repo.checkout(payload);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (order) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Order ${order.reference} placed.')),
        );
        _load();
      },
    );
  }

  String _money(int minor, String currency) {
    final symbol = currency == 'NGN' ? '₦' : '$currency ';
    return '$symbol${(minor / 100).toStringAsFixed(2)}';
  }

  @override
  void dispose() {
    _coupon.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cart = _cart;
    return Scaffold(
      appBar: AppBar(title: const Text('Your cart')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : cart == null || cart.items.isEmpty
              ? const Center(child: Text('Your cart is empty.'))
              : Column(
                  children: <Widget>[
                    Expanded(
                      child: ListView(
                        children: cart.items
                            .map(
                              (line) => ListTile(
                                title: Text(line.name),
                                subtitle: Text('Qty ${line.quantity}'),
                                trailing: Text(_money(line.lineTotalMinor, cart.currency)),
                              ),
                            )
                            .toList(),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      child: Row(
                        children: <Widget>[
                          Expanded(
                            child: TextField(
                              controller: _coupon,
                              decoration: const InputDecoration(hintText: 'Coupon code'),
                            ),
                          ),
                          TextButton(onPressed: _applyCoupon, child: const Text('Apply')),
                        ],
                      ),
                    ),
                    SwitchListTile(
                      title: const Text('Pick up (no shipping)'),
                      value: _pickup,
                      onChanged: (v) => setState(() => _pickup = v),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: <Widget>[
                          Text(
                            'Subtotal ${_money(cart.subtotalMinor, cart.currency)}',
                            style: const TextStyle(fontWeight: FontWeight.bold),
                          ),
                          FilledButton(onPressed: _checkout, child: const Text('Place order')),
                        ],
                      ),
                    ),
                  ],
                ),
    );
  }
}
