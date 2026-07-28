import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/admin_entities.dart';

/// Contract for the Platform Administration feature.
abstract class AdminRepository {
  Future<Either<Failure, List<AuditEntryView>>> recentAudit();

  Future<Either<Failure, List<TicketView>>> tickets(String status);

  Future<Either<Failure, TicketView>> replyTicket(String id, String body);

  Future<Either<Failure, TicketView>> resolveTicket(String id);
}
