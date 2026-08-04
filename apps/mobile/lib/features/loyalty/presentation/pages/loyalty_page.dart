import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/loyalty_entities.dart';
import '../../domain/repositories/loyalty_repository.dart';

/// The mobile rewards hub: balance and tier progress, the rewards catalogue to
/// redeem, and points history. Every call goes through the Loyalty context.
class LoyaltyPage extends StatefulWidget {
  const LoyaltyPage({super.key});

  @override
  State<LoyaltyPage> createState() => _LoyaltyPageState();
}

class _LoyaltyPageState extends State<LoyaltyPage> {
  final LoyaltyRepository _repo = sl<LoyaltyRepository>();

  LoyaltyAccountView? _account;
  List<RewardView> _rewards = <RewardView>[];
  List<LedgerEntryView> _ledger = <LedgerEntryView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final account = await _repo.account();
    final rewards = await _repo.rewards();
    final ledger = await _repo.ledger();
    if (!mounted) return;
    setState(() {
      account.fold((_) => _account = null, (a) => _account = a);
      rewards.fold((_) => _rewards = <RewardView>[], (r) => _rewards = r);
      ledger.fold((_) => _ledger = <LedgerEntryView>[], (l) => _ledger = l);
      _loading = false;
    });
  }

  Future<void> _redeem(RewardView reward) async {
    final result = await _repo.redeem(reward.id);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (redemption) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Redeemed — your code is ${redemption.code}')),
        );
        _load();
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Rewards')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: <Widget>[
                  if (_account != null) _BalanceCard(account: _account!),
                  const SizedBox(height: 16),
                  Text('Rewards', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 8),
                  if (_rewards.isEmpty)
                    const Text('No rewards available right now.')
                  else
                    ..._rewards.map((RewardView r) => _RewardTile(
                          reward: r,
                          affordable: (_account?.balance ?? 0) >= r.pointsCost,
                          onRedeem: () => _redeem(r),
                        )),
                  const SizedBox(height: 16),
                  Text('Points history', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 8),
                  if (_ledger.isEmpty)
                    const Text('No activity yet.')
                  else
                    ..._ledger.map((LedgerEntryView e) => ListTile(
                          dense: true,
                          title: Text(e.reason),
                          trailing: Text(
                            '${e.points >= 0 ? '+' : ''}${e.points}',
                            style: TextStyle(
                              color: e.points >= 0 ? const Color(0xFF2B7A2B) : const Color(0xFFB3261E),
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        )),
                ],
              ),
            ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.account});

  final LoyaltyAccountView account;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: <Widget>[
                Text('${account.balance}', style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(width: 6),
                const Text('points'),
              ],
            ),
            const SizedBox(height: 6),
            Chip(label: Text(account.tier.name), visualDensity: VisualDensity.compact),
            if (account.nextTier != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text('${account.nextTier!.pointsToGo} points to ${account.nextTier!.name}'),
              ),
          ],
        ),
      ),
    );
  }
}

class _RewardTile extends StatelessWidget {
  const _RewardTile({required this.reward, required this.affordable, required this.onRedeem});

  final RewardView reward;
  final bool affordable;
  final VoidCallback onRedeem;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        title: Text(reward.name),
        subtitle: Text(reward.description),
        trailing: FilledButton(
          onPressed: affordable ? onRedeem : null,
          child: Text('${reward.pointsCost} pts'),
        ),
      ),
    );
  }
}
