import '../../domain/entities/analytics_entities.dart';

DashboardView dashboardFromJson(Map<String, dynamic> json) {
  final kpis = (json['kpis'] as List<dynamic>? ?? <dynamic>[])
      .map((dynamic e) => _kpi(e as Map<String, dynamic>))
      .toList();
  final breakdowns = <String, List<BreakdownRow>>{};
  final raw = json['breakdowns'] as Map<String, dynamic>? ?? <String, dynamic>{};
  raw.forEach((String name, dynamic rows) {
    final map = rows as Map<String, dynamic>;
    breakdowns[name] = map.entries
        .map((entry) => BreakdownRow(label: entry.key, value: (entry.value as num).toInt()))
        .toList();
  });
  return DashboardView(
    type: json['type'] as String? ?? '',
    kpis: kpis,
    breakdowns: breakdowns,
  );
}

KpiView _kpi(Map<String, dynamic> json) {
  return KpiView(
    key: json['key'] as String? ?? '',
    label: json['label'] as String? ?? '',
    value: (json['value'] as num?)?.toInt() ?? 0,
    unit: json['unit'] as String? ?? 'count',
    deltaPct: (json['delta_pct'] as num?)?.toDouble(),
  );
}
