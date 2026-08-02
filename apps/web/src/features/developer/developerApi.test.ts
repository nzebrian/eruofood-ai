import { afterEach, describe, expect, it, vi } from 'vitest';
import { developerApi } from './developerApi';

function mockJson(body: unknown, status = 200) {
  const hasBody = status !== 204;
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(hasBody ? JSON.stringify(body) : null, {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

describe('developerApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('creates an application with scopes', async () => {
    const fetchMock = mockJson({ data: { id: 'app1' } }, 201);
    await developerApi.createApplication('App', 'desc', ['foods:read']);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/v1/developer/applications');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({ scopes: ['foods:read'] });
  });

  it('issues an API key for an application', async () => {
    const fetchMock = mockJson({ data: { id: 'k1', key: 'efk_live_x.secret' } }, 201);
    await developerApi.issueKey('app1', 'Prod', ['foods:read'], 90);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/v1/developer/applications/app1/keys');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({ ttl_days: 90 });
  });

  it('revokes a key via DELETE', async () => {
    const fetchMock = mockJson({ data: { revoked: true } });
    await developerApi.revokeKey('k1');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/v1/developer/keys/k1');
    expect((init as RequestInit).method).toBe('DELETE');
  });

  it('creates a webhook', async () => {
    const fetchMock = mockJson({ data: { id: 'w1', secret: 'whsec_x' } }, 201);
    await developerApi.createWebhook('app1', 'https://x.test/h', ['review.published']);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/v1/developer/applications/app1/webhooks');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({ url: 'https://x.test/h' });
  });

  it('fetches the public scope catalogue', async () => {
    const fetchMock = mockJson({ data: { scopes: [{ scope: 'foods:read', description: 'x' }] } });
    await developerApi.scopes();
    expect(fetchMock.mock.calls[0]?.[0] as string).toContain('/public/v1/scopes');
  });
});
