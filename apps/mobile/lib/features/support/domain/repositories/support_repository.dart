import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/support_entities.dart';

/// Contract for the Customer Support, Helpdesk & CRM feature.
abstract class SupportRepository {
  Future<Either<Failure, List<TicketSummaryView>>> myTickets();

  Future<Either<Failure, TicketView>> ticket(String id);

  Future<Either<Failure, TicketView>> openTicket(
    String subject,
    String category,
    String body,
    String priority,
  );

  Future<Either<Failure, TicketView>> reply(String id, String body);

  Future<Either<Failure, Unit>> submitCsat(String id, int score, String? comment);

  Future<Either<Failure, List<ArticleView>>> articles(String query);
}
