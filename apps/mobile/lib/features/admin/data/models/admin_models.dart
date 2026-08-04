import '../../domain/entities/admin_entities.dart';

AuditEntryView auditFromJson(Map<String, dynamic> json) {
  return AuditEntryView(
    id: json['id'] as String? ?? '',
    category: json['category'] as String? ?? '',
    action: json['action'] as String? ?? '',
    createdAt: json['created_at'] as String? ?? '',
    actorId: json['actor_id'] as String?,
    subjectId: json['subject_id'] as String?,
  );
}

TicketMessageView ticketMessageFromJson(Map<String, dynamic> json) {
  return TicketMessageView(
    id: json['id'] as String? ?? '',
    authorId: json['author_id'] as String? ?? '',
    body: json['body'] as String? ?? '',
    internal: json['internal'] as bool? ?? false,
    createdAt: json['created_at'] as String? ?? '',
  );
}

TicketView ticketFromJson(Map<String, dynamic> json) {
  final messages = (json['messages'] as List<dynamic>? ?? <dynamic>[])
      .map((dynamic e) => ticketMessageFromJson(e as Map<String, dynamic>))
      .toList();
  return TicketView(
    id: json['id'] as String? ?? '',
    subject: json['subject'] as String? ?? '',
    category: json['category'] as String? ?? '',
    status: json['status'] as String? ?? '',
    priority: json['priority'] as String? ?? 'normal',
    messages: messages,
    assigneeId: json['assignee_id'] as String?,
  );
}
