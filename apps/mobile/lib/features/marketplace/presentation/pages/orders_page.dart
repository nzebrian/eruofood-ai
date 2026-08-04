import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/marketplace_entities.dart';
import '../../domain/repositories/marketplace_repository.dart';

/// The customer's order history.
class OrdersPage extends StatefulWidget {
  const OrdersPage({super.key});

  @override
  State<OrdersPage> createState() => _OrdersPageState();
}

class _OrdersPageState extends State<OrdersPage> {
  final MarketplaceRepository _repo = sl<MarketplaceRepository>();
  List<OrderSummaryView> _orders = <OrderSummaryView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.orders();
    if (!mounted) {
      return;
    }
    setState(() {
      _orders = result.getOrElse(() => <OrderSummaryView>[]);
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('My orders')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _orders.isEmpty
              ? const Center(child: Text('No orders yet.'))
              : ListView(
                  children: _orders
                      .map(
                        (o) => ListTile(
                          title: Text(o.reference),
                          subtitle: Text('${o.status} · ${o.fulfilment}'),
                          trailing: Text(formatMoney(o.totalMinor, o.currency)),
                        ),
                      )
                      .toList(),
                ),
    );
  }
}
