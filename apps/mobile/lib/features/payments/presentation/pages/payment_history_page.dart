import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/payments_entities.dart';
import '../../domain/repositories/payments_repository.dart';

/// The user's payment history.
class PaymentHistoryPage extends StatefulWidget {
  const PaymentHistoryPage({super.key});

  @override
  State<PaymentHistoryPage> createState() => _PaymentHistoryPageState();
}

class _PaymentHistoryPageState extends State<PaymentHistoryPage> {
  final PaymentsRepository _repo = sl<PaymentsRepository>();
  List<PaymentView> _payments = <PaymentView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final result = await _repo.payments();
    if (!mounted) return;
    setState(() {
      _payments = result.getOrElse(() => <PaymentView>[]);
      _loading = false;
    });
  }

  String _money(int minor, String currency) {
    final symbol = currency == 'NGN' ? '₦' : '$currency ';
    return '$symbol${(minor / 100).toStringAsFixed(2)}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Payment history')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _payments.isEmpty
              ? const Center(child: Text('No payments yet.'))
              : ListView(
                  children: _payments
                      .map(
                        (p) => ListTile(
                          title: Text(p.reference),
                          subtitle: Text('${p.provider} · ${p.status}'),
                          trailing: Text(_money(p.amountMinor, p.currency)),
                        ),
                      )
                      .toList(),
                ),
    );
  }
}
