/** Types for the Analytics, Business Intelligence & Reporting module. */

export interface Kpi {
  key: string;
  label: string;
  value: number;
  unit: 'count' | 'money' | 'tokens';
  delta_pct: number | null;
}

export interface DataPoint {
  bucket: string;
  value: number;
}

export interface ChartSeries {
  metric: string;
  label: string;
  unit: string;
  points: DataPoint[];
}

export interface Dashboard {
  type: string;
  range: { from: string; to: string };
  kpis: Kpi[];
  charts: ChartSeries[];
  breakdowns: Record<string, Record<string, number>>;
}

export interface Report {
  id: string;
  key: string;
  title: string;
  range: { from: string; to: string };
  columns: string[];
  rows: (string | number)[][];
  status: string;
  generated_at: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export const DASHBOARDS = ['executive', 'operations', 'finance', 'admin'] as const;

/** Format a KPI value according to its unit. */
export function formatKpi(value: number, unit: string): string {
  if (unit === 'money') {
    return `₦${(value / 100).toLocaleString('en-NG', { maximumFractionDigits: 0 })}`;
  }
  return value.toLocaleString('en-NG');
}
