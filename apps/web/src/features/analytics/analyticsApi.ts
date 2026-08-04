import { apiClient } from '@lib/apiClient';
import type { Dashboard, Paginated, Report } from './types';

function query(params: Record<string, string | number | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** Client for the Analytics REST endpoints (mounted at /analytics). */
export const analyticsApi = {
  dashboard: (type: string, days: number) =>
    apiClient.get<Dashboard>(`/analytics/dashboards/${type}${query({ days })}`),
  catalogue: () => apiClient.get<{ reports: string[] }>('/analytics/reports/catalogue'),
  recentReports: () => apiClient.getPage<Paginated<Report>>('/analytics/reports'),
  generate: (key: string, days: number) =>
    apiClient.post<Report>(`/analytics/reports${query({ days })}`, { key }),
  report: (id: string) => apiClient.get<Report>(`/analytics/reports/${id}`),
  exportPath: (id: string, format: string) =>
    `/analytics/reports/${id}/export${query({ format })}`,
};
