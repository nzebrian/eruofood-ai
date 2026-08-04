import 'package:equatable/equatable.dart';

/// An audit-log entry shown in the admin overview.
class AuditEntryView extends Equatable {
  const AuditEntryView({
    required this.id,
    required this.category,
    required this.action,
    required this.createdAt,
    this.actorId,
    this.subjectId,
  });

  final String id;
  final String category;
  final String action;
  final String createdAt;
  final String? actorId;
  final String? subjectId;

  @override
  List<Object?> get props => <Object?>[id, category, action, createdAt, actorId, subjectId];
}

/// A message on a support ticket.
class TicketMessageView extends Equatable {
  const TicketMessageView({
    required this.id,
    required this.authorId,
    required this.body,
    required this.internal,
    required this.createdAt,
  });

  final String id;
  final String authorId;
  final String body;
  final bool internal;
  final String createdAt;

  @override
  List<Object?> get props => <Object?>[id, authorId, body, internal, createdAt];
}

/// A support ticket.
class TicketView extends Equatable {
  const TicketView({
    required this.id,
    required this.subject,
    required this.category,
    required this.status,
    required this.priority,
    required this.messages,
    this.assigneeId,
  });

  final String id;
  final String subject;
  final String category;
  final String status;
  final String priority;
  final List<TicketMessageView> messages;
  final String? assigneeId;

  @override
  List<Object?> get props =>
      <Object?>[id, subject, category, status, priority, messages, assigneeId];
}
