import '../../domain/entities/support_entities.dart';

SlaView slaFromJson(Map<String, dynamic> json) {
  return SlaView(
    state: json['state'] as String? ?? 'on_track',
    breached: json['breached'] as bool? ?? false,
  );
}

TicketMessageView messageFromJson(Map<String, dynamic> json) {
  return TicketMessageView(
    id: json['id'] as String? ?? '',
    authorType: json['author_type'] as String? ?? 'customer',
    body: json['body'] as String? ?? '',
    internal: json['internal'] as bool? ?? false,
    createdAt: json['created_at'] as String? ?? '',
  );
}

TicketView ticketFromJson(Map<String, dynamic> json) {
  final messages = (json['messages'] as List<dynamic>? ?? <dynamic>[])
      .map((dynamic e) => messageFromJson(e as Map<String, dynamic>))
      .toList();
  return TicketView(
    id: json['id'] as String? ?? '',
    ref: json['ref'] as String? ?? '',
    subject: json['subject'] as String? ?? '',
    status: json['status'] as String? ?? 'new',
    priority: json['priority'] as String? ?? 'normal',
    sla: slaFromJson(json['sla'] as Map<String, dynamic>? ?? <String, dynamic>{}),
    messages: messages,
    csatScore: (json['csat_score'] as num?)?.toInt(),
  );
}

TicketSummaryView ticketSummaryFromJson(Map<String, dynamic> json) {
  return TicketSummaryView(
    id: json['id'] as String? ?? '',
    ref: json['ref'] as String? ?? '',
    subject: json['subject'] as String? ?? '',
    status: json['status'] as String? ?? 'new',
    priority: json['priority'] as String? ?? 'normal',
    sla: slaFromJson(json['sla'] as Map<String, dynamic>? ?? <String, dynamic>{}),
  );
}

ArticleView articleFromJson(Map<String, dynamic> json) {
  return ArticleView(
    slug: json['slug'] as String? ?? '',
    title: json['title'] as String? ?? '',
    category: json['category'] as String? ?? '',
    body: json['body'] as String? ?? '',
  );
}
