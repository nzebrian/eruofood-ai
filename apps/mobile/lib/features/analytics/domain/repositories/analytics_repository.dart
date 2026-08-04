import 'package:dartz/dartz.dart';

import '../../../../core/error/failure.dart';
import '../entities/analytics_entities.dart';

/// Contract for the Analytics feature.
abstract class AnalyticsRepository {
  Future<Either<Failure, DashboardView>> dashboard(String type, int days);
}
