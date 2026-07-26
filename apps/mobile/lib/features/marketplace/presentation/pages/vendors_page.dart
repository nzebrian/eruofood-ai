import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/marketplace_entities.dart';
import '../../domain/repositories/marketplace_repository.dart';
import 'vendor_storefront_page.dart';

/// Browse & search verified vendors.
class VendorsPage extends StatefulWidget {
  const VendorsPage({super.key});

  @override
  State<VendorsPage> createState() => _VendorsPageState();
}

class _VendorsPageState extends State<VendorsPage> {
  final MarketplaceRepository _repo = sl<MarketplaceRepository>();
  final TextEditingController _search = TextEditingController();
  List<VendorSummary> _vendors = <VendorSummary>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load(null);
  }

  Future<void> _load(String? query) async {
    setState(() => _loading = true);
    final result = await _repo.vendors(query: query);
    if (!mounted) {
      return;
    }
    setState(() {
      _vendors = result.getOrElse(() => <VendorSummary>[]);
      _loading = false;
    });
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Vendors')),
      body: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _search,
              decoration: const InputDecoration(hintText: 'Search vendors…', prefixIcon: Icon(Icons.search)),
              onSubmitted: _load,
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _vendors.isEmpty
                    ? const Center(child: Text('No vendors found.'))
                    : ListView(
                        children: _vendors
                            .map(
                              (v) => ListTile(
                                title: Text(v.name),
                                subtitle: Text('${v.category} · ⭐ ${v.ratingAverage} (${v.ratingCount})'),
                                trailing: v.featured ? const Icon(Icons.star, color: Colors.amber) : null,
                                onTap: () => Navigator.of(context).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => VendorStorefrontPage(vendorId: v.id, vendorName: v.name),
                                  ),
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
}
