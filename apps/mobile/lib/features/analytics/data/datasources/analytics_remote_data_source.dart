import '../../../../core/network/api_client.dart';
import '../../domain/entities/analytics_entities.dart';
import '../models/analytics_models.dart';

/// Reads the Analytics REST endpoints (mounted at /analytics).
class AnalyticsRemoteDataSource {
  AnalyticsRemoteDataSource(this._client);

  final ApiClient _client;

  Future<DashboardView> dashboard(String type, int days) async {
    final res = await _client.get<dynamic>('/analytics/dashboards/$type', query: <String, dynamic>{'days': days});
    final data = (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return dashboardFromJson(data);
  }
}
