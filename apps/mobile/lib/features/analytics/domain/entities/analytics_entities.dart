import 'package:equatable/equatable.dart';

/// A KPI card.
class KpiView extends Equatable {
  const KpiView({
    required this.key,
    required this.label,
    required this.value,
    required this.unit,
    this.deltaPct,
  });

  final String key;
  final String label;
  final int value;
  final String unit; // count | money | tokens
  final double? deltaPct;

  @override
  List<Object?> get props => <Object?>[key, label, value, unit, deltaPct];
}

/// A dimension breakdown row.
class BreakdownRow extends Equatable {
  const BreakdownRow({required this.label, required this.value});

  final String label;
  final int value;

  @override
  List<Object?> get props => <Object?>[label, value];
}

/// An assembled dashboard.
class DashboardView extends Equatable {
  const DashboardView({required this.type, required this.kpis, required this.breakdowns});

  final String type;
  final List<KpiView> kpis;
  final Map<String, List<BreakdownRow>> breakdowns;

  @override
  List<Object?> get props => <Object?>[type, kpis, breakdowns];
}
