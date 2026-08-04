import 'package:equatable/equatable.dart';

/// The computed SLA standing of a ticket.
class SlaView extends Equatable {
  const SlaView({required this.state, required this.breached});

  final String state;
  final bool breached;

  @override
  List<Object?> get props => <Object?>[state, breached];
}

/// A message on a support ticket.
class TicketMessageView extends Equatable {
  const TicketMessageView({
    required this.id,
    required this.authorType,
    required this.body,
    required this.internal,
    required this.createdAt,
  });

  final String id;
  final String authorType;
  final String body;
  final bool internal;
  final String createdAt;

  @override
  List<Object?> get props => <Object?>[id, authorType, body, internal, createdAt];
}

/// A support ticket with its conversation.
class TicketView extends Equatable {
  const TicketView({
    required this.id,
    required this.ref,
    required this.subject,
    required this.status,
    required this.priority,
    required this.sla,
    required this.messages,
    this.csatScore,
  });

  final String id;
  final String ref;
  final String subject;
  final String status;
  final String priority;
  final SlaView sla;
  final List<TicketMessageView> messages;
  final int? csatScore;

  @override
  List<Object?> get props =>
      <Object?>[id, ref, subject, status, priority, sla, messages, csatScore];
}

/// A ticket summary for the list.
class TicketSummaryView extends Equatable {
  const TicketSummaryView({
    required this.id,
    required this.ref,
    required this.subject,
    required this.status,
    required this.priority,
    required this.sla,
  });

  final String id;
  final String ref;
  final String subject;
  final String status;
  final String priority;
  final SlaView sla;

  @override
  List<Object?> get props => <Object?>[id, ref, subject, status, priority, sla];
}

/// A knowledge-base article summary.
class ArticleView extends Equatable {
  const ArticleView({required this.slug, required this.title, required this.category, required this.body});

  final String slug;
  final String title;
  final String category;
  final String body;

  @override
  List<Object?> get props => <Object?>[slug, title, category, body];
}
