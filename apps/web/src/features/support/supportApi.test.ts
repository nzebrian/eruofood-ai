import { afterEach, describe, expect, it, vi } from 'vitest';
import { supportApi } from './supportApi';

function mockJson(body: unknown, status = 200) {
  const hasBody = status !== 204;
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(hasBody ? JSON.stringify(body) : null, {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

describe('supportApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('opens a ticket via POST', async () => {
    const fetchMock = mockJson({ data: { id: 't1', status: 'new' } }, 201);
    await supportApi.openTicket({ subject: 'S', category: 'billing', body: 'B', priority: 'high' });
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/support/tickets');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({ priority: 'high' });
  });

  it('filters the agent queue by status and unassigned', async () => {
    const fetchMock = mockJson({ data: [], meta: { page: 1, per_page: 20, total: 0 } });
    await supportApi.queue('open', true);
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/support/agent/tickets');
    expect(url).toContain('status=open');
    expect(url).toContain('unassigned=true');
  });

  it('votes an article helpful', async () => {
    const fetchMock = mockJson({ data: { helpful_yes: 1 } });
    await supportApi.voteArticle('reset-password', true);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/support/kb/articles/reset-password/vote');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ helpful: true });
  });

  it('fetches the admin dashboard with a day range', async () => {
    const fetchMock = mockJson({ data: { queue: {}, sla: {}, csat: {} } });
    await supportApi.dashboard(30);
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/support/admin/dashboard');
    expect(url).toContain('days=30');
  });
});
