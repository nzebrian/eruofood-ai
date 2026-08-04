import '../../../../core/network/api_client.dart';
import '../../domain/entities/support_entities.dart';
import '../models/support_models.dart';

/// Reads the Customer Support REST endpoints (mounted at /support).
class SupportRemoteDataSource {
  SupportRemoteDataSource(this._client);

  final ApiClient _client;

  Future<List<TicketSummaryView>> myTickets() async {
    final res = await _client.get<dynamic>('/support/tickets');
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => ticketSummaryFromJson(e as Map<String, dynamic>)).toList();
  }

  Future<TicketView> ticket(String id) async {
    final res = await _client.get<dynamic>('/support/tickets/$id');
    return ticketFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<TicketView> openTicket(String subject, String category, String body, String priority) async {
    final res = await _client.post<dynamic>('/support/tickets', data: <String, dynamic>{
      'subject': subject,
      'category': category,
      'body': body,
      'priority': priority,
    });
    return ticketFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<TicketView> reply(String id, String body) async {
    final res = await _client.post<dynamic>('/support/tickets/$id/reply', data: <String, dynamic>{'body': body});
    return ticketFromJson((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
  }

  Future<void> submitCsat(String id, int score, String? comment) async {
    await _client.post<dynamic>('/support/tickets/$id/csat', data: <String, dynamic>{
      'score': score,
      if (comment != null) 'comment': comment,
    });
  }

  Future<List<ArticleView>> articles(String query) async {
    final res = await _client.get<dynamic>('/support/kb/articles', query: <String, dynamic>{'q': query});
    final rows = (res.data as Map<String, dynamic>)['data'] as List<dynamic>? ?? <dynamic>[];
    return rows.map((dynamic e) => articleFromJson(e as Map<String, dynamic>)).toList();
  }
}
