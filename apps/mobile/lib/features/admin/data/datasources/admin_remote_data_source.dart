import '../../../../core/network/api_client.dart';
import '../../domain/entities/admin_entities.dart';
import '../models/admin_models.dart';

/// Reads the Platform Administration REST endpoints (mounted at /admin).
class AdminRemoteDataSource {
  AdminRemoteDataSource(this._client);

  final ApiClient _client;

  Future<List<AuditEntryView>> recentAudit() async {
    final res = await _client.get<dynamic>('/admin/audit', query: <String, dynamic>{'per_page': 20});
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => auditFromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<TicketView>> tickets(String status) async {
    final res = await _client.get<dynamic>('/admin/support/tickets', query: <String, dynamic>{'status': status});
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => ticketFromJson(e as Map<String, dynamic>)).toList();
  }

  Future<TicketView> replyTicket(String id, String body) async {
    final res = await _client.post<dynamic>('/admin/support/tickets/$id/reply', data: <String, dynamic>{'body': body});
    final data = (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return ticketFromJson(data);
  }

  Future<TicketView> resolveTicket(String id) async {
    final res = await _client.post<dynamic>('/admin/support/tickets/$id/resolve');
    final data = (res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
    return ticketFromJson(data);
  }
}
