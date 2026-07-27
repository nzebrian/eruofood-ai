import { afterEach, describe, expect, it, vi } from 'vitest';
import { notificationsApi } from './notificationsApi';

describe('notificationsApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('lists unread notifications with the query flag', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { page: 1, per_page: 20, total: 0 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    await notificationsApi.list(true);
    expect(fetchMock.mock.calls[0]?.[0] as string).toContain('/notifications?unread=1');
  });

  it('updates channel preferences via PUT', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    await notificationsApi.setChannels('payment', ['email', 'push']);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/notifications/preferences/channels');
    expect((init as RequestInit).method).toBe('PUT');
    const body = JSON.parse((init as RequestInit).body as string) as { category: string; channels: string[] };
    expect(body.category).toBe('payment');
    expect(body.channels).toEqual(['email', 'push']);
  });

  it('sends a chat message via POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { id: 'm1' } }), { status: 201, headers: { 'Content-Type': 'application/json' } }),
    );
    await notificationsApi.sendMessage('c1', 'hi');
    expect(fetchMock.mock.calls[0]?.[0] as string).toContain('/notifications/conversations/c1/messages');
  });
});
