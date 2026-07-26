import 'package:flutter/material.dart';

import 'cart_page.dart';
import 'orders_page.dart';
import 'vendors_page.dart';

/// Landing screen for the marketplace (the "Order" tab).
class MarketplaceHubPage extends StatelessWidget {
  const MarketplaceHubPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Order food')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: const <Widget>[
          _HubTile(
            icon: Icons.storefront_outlined,
            title: 'Browse vendors',
            subtitle: 'Restaurants, kitchens and market vendors near you.',
            page: VendorsPage(),
          ),
          _HubTile(
            icon: Icons.shopping_cart_outlined,
            title: 'Cart & checkout',
            subtitle: 'Review your cart and place an order.',
            page: CartPage(),
          ),
          _HubTile(
            icon: Icons.receipt_long_outlined,
            title: 'My orders',
            subtitle: 'Track your orders and history.',
            page: OrdersPage(),
          ),
        ],
      ),
    );
  }
}

class _HubTile extends StatelessWidget {
  const _HubTile({required this.icon, required this.title, required this.subtitle, required this.page});

  final IconData icon;
  final String title;
  final String subtitle;
  final Widget page;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: Icon(icon, size: 32),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => page)),
      ),
    );
  }
}
