import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/payments_entities.dart';
import '../../domain/repositories/payments_repository.dart';
import 'payment_history_page.dart';

/// The user's wallet: balance, top-up and recent statement.
class WalletPage extends StatefulWidget {
  const WalletPage({super.key});

  @override
  State<WalletPage> createState() => _WalletPageState();
}

class _WalletPageState extends State<WalletPage> {
  final PaymentsRepository _repo = sl<PaymentsRepository>();
  WalletView? _wallet;
  List<WalletTxnView> _statement = <WalletTxnView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final wallet = await _repo.wallet();
    final statement = await _repo.statement();
    if (!mounted) return;
    setState(() {
      _wallet = wallet.fold((_) => null, (w) => w);
      _statement = statement.getOrElse(() => <WalletTxnView>[]);
      _loading = false;
    });
  }

  Future<void> _topUp() async {
    final controller = TextEditingController(text: '5000');
    final amount = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Top up wallet'),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(prefixText: '₦ '),
        ),
        actions: <Widget>[
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, ((double.tryParse(controller.text) ?? 0) * 100).round()),
            child: const Text('Top up'),
          ),
        ],
      ),
    );
    if (amount == null || amount <= 0) return;

    final result = await _repo.topUp(amount, 'customer@example.com');
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (_) => _load(),
    );
  }

  String _money(int minor, String currency) {
    final symbol = currency == 'NGN' ? '₦' : '$currency ';
    return '$symbol${(minor / 100).toStringAsFixed(2)}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Wallet'),
        actions: <Widget>[
          IconButton(
            icon: const Icon(Icons.receipt_long),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const PaymentHistoryPage()),
            ),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                children: <Widget>[
                  Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      children: <Widget>[
                        const Text('Balance'),
                        const SizedBox(height: 8),
                        Text(
                          _wallet != null ? _money(_wallet!.balanceMinor, _wallet!.currency) : '—',
                          style: const TextStyle(fontSize: 34, fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: _topUp,
                          icon: const Icon(Icons.add),
                          label: const Text('Top up'),
                        ),
                      ],
                    ),
                  ),
                  const Divider(),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    child: Text('Recent transactions', style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                  if (_statement.isEmpty)
                    const Padding(padding: EdgeInsets.all(16), child: Text('No transactions yet.'))
                  else
                    ..._statement.map(
                      (t) => ListTile(
                        leading: Icon(
                          t.direction == 'credit' ? Icons.arrow_downward : Icons.arrow_upward,
                          color: t.direction == 'credit' ? Colors.green : Colors.red,
                        ),
                        title: Text(t.description ?? t.type),
                        trailing: Text(
                          '${t.direction == 'credit' ? '+' : '−'}${_money(t.amountMinor, 'NGN')}',
                          style: TextStyle(color: t.direction == 'credit' ? Colors.green : Colors.red),
                        ),
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}
