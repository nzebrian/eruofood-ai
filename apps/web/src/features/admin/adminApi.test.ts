import { afterEach, describe, expect, it, vi } from 'vitest';
import { adminApi } from './adminApi';

function mockJson(body: unknown, status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

describe('adminApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('searches users with query params', async () => {
    const fetchMock = mockJson({ data: [], meta: { page: 1, per_page: 20, total: 0 } });
    await adminApi.users('ade', 'active', 2);
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/admin/users');
    expect(url).toContain('q=ade');
    expect(url).toContain('status=active');
    expect(url).toContain('page=2');
  });

  it('suspends a user via POST with a reason', async () => {
    const fetchMock = mockJson({ data: { user_id: 'u1', status: 'suspended' } });
    await adminApi.suspendUser('u1', 'spam');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/admin/users/u1/suspend');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ reason: 'spam' });
  });

  it('updates a setting via PUT', async () => {
    const fetchMock = mockJson({ data: { key: 'app.name', value: 'X' } });
    await adminApi.updateSetting('app.name', 'X');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/admin/settings/app.name');
    expect((init as RequestInit).method).toBe('PUT');
  });

  it('creates a CMS page via POST', async () => {
    const fetchMock = mockJson({ data: { id: 'p1', status: 'draft' } }, 201);
    await adminApi.createPage({ type: 'page', title: 'T', body: 'B' });
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/admin/cms/pages');
    expect((init as RequestInit).method).toBe('POST');
  });
});
