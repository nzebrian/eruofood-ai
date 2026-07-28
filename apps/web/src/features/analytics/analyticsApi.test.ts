import { afterEach, describe, expect, it, vi } from 'vitest';
import { analyticsApi } from './analyticsApi';
import { formatKpi } from './types';

describe('analyticsApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('fetches a dashboard with a day range', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { type: 'executive', kpis: [], charts: [], breakdowns: {} } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    await analyticsApi.dashboard('executive', 30);
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/analytics/dashboards/executive');
    expect(url).toContain('days=30');
  });

  it('generates a report via POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { id: 'r1', key: 'financial' } }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    await analyticsApi.generate('financial', 7);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/analytics/reports');
    expect((init as RequestInit).method).toBe('POST');
  });

  it('formats money and count KPIs', () => {
    expect(formatKpi(150000000, 'money')).toBe('₦1,500,000');
    expect(formatKpi(42, 'count')).toBe('42');
  });
});
