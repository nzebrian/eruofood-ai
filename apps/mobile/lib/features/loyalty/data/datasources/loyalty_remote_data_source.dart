import '../../../../core/network/api_client.dart';
import '../../domain/entities/loyalty_entities.dart';
import '../models/loyalty_models.dart';

/// Reads the Loyalty, Rewards & Referrals REST endpoints (mounted at /loyalty).
class LoyaltyRemoteDataSource {
  LoyaltyRemoteDataSource(this._client);

  final ApiClient _client;

  Future<LoyaltyAccountView> account() async {
    final res = await _client.get<dynamic>('/loyalty/me');
    return accountFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<List<LedgerEntryView>> ledger() async {
    final res = await _client.get<dynamic>('/loyalty/ledger');
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => ledgerEntryFromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<RewardView>> rewards() async {
    final res = await _client.get<dynamic>('/loyalty/rewards');
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => rewardFromJson(e as Map<String, dynamic>)).toList();
  }

  Future<RedemptionView> redeem(String rewardId) async {
    final res = await _client.post<dynamic>('/loyalty/rewards/$rewardId/redeem', data: <String, dynamic>{});
    return redemptionFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<String> referralCode() async {
    final res = await _client.get<dynamic>('/loyalty/referrals/code');
    return (res.data as Map<String, dynamic>)['data']['code'] as String? ?? '';
  }

  Future<void> applyReferral(String code) async {
    await _client.post<dynamic>('/loyalty/referrals/apply', data: <String, dynamic>{'code': code});
  }
}
