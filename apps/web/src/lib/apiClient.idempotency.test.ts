import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from './apiClient';
import { tokenStorage } from './tokenStorage';

/**
 * M43 — the transport half: does the key survive a 401 token refresh?
 *
 * This is the milestone's primary acceptance criterion, and it is the one that
 * a plausible-looking implementation gets wrong. `apiClient.request()` re-issues
 * the whole request after refreshing the token. If the key were minted inside
 * the transport — the obvious place to put it — the replay would mint a second
 * one, the server would see two unrelated operations, and the customer would be
 * charged twice by the very mechanism added to stop that.
 *
 * So the transport takes the key as an argument and never generates one, and
 * these tests prove the replay carries the original value.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function headerOf(call: [unknown, unknown] | undefined, name: string): string | undefined {
  const init = call?.[1] as RequestInit | undefined;

  return (init?.headers as Record<string, string> | undefined)?.[name];
}

describe('apiClient.postIdempotent', () => {
  beforeEach(() => {
    tokenStorage.set({ accessToken: 'access-1', refreshToken: 'refresh-1' });
  });

  afterEach(() => {
    tokenStorage.clear();
    vi.restoreAllMocks();
  });

  it('sends the key as the Idempotency-Key header, spelled exactly', () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(jsonResponse({ data: { id: 'o1' } }, 201));

    return apiClient.postIdempotent('/commerce/checkout', { pickup: true }, 'KEY-A').then(() => {
      const headers = (fetchMock.mock.calls[0]?.[1] as RequestInit).headers as Record<
        string,
        string
      >;

      expect(headers['Idempotency-Key']).toBe('KEY-A');
      // Spelled once, and not smuggled anywhere else.
      expect(Object.keys(headers).filter((h) => /idempotenc/i.test(h))).toEqual([
        'Idempotency-Key',
      ]);
    });
  });

  it('never puts the key in the URL', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(jsonResponse({ data: {} }, 201));

    await apiClient.postIdempotent('/payments/payments', { amount_minor: 100 }, 'SECRET-KEY');

    expect(fetchMock.mock.calls[0]?.[0] as string).not.toContain('SECRET-KEY');
  });

  it('replays the SAME key after a 401 token refresh', async () => {
    // The mandated sequence: KEY_A → 401 → refresh → replay must carry KEY_A.
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse({ error: { code: 'UNAUTHENTICATED' } }, 401))
      .mockResolvedValueOnce(
        jsonResponse({ data: { access_token: 'access-2', refresh_token: 'refresh-2' } }, 200),
      )
      .mockResolvedValueOnce(jsonResponse({ data: { id: 'o1' } }, 201));

    await apiClient.postIdempotent('/commerce/checkout', { pickup: true }, 'KEY_A');

    expect(fetchMock).toHaveBeenCalledTimes(3);

    const first = headerOf(fetchMock.mock.calls[0] as [unknown, unknown], 'Idempotency-Key');
    const replay = headerOf(fetchMock.mock.calls[2] as [unknown, unknown], 'Idempotency-Key');

    expect(first).toBe('KEY_A');
    expect(replay).toBe('KEY_A');
    // Stated as its own assertion so a failure reads as what it is: one logical
    // operation became two.
    expect(replay).toBe(first);

    // The refresh call itself is not a money-moving operation and carries no key.
    expect(
      headerOf(fetchMock.mock.calls[1] as [unknown, unknown], 'Idempotency-Key'),
    ).toBeUndefined();

    // And the replay does pick up the refreshed credential.
    expect(headerOf(fetchMock.mock.calls[2] as [unknown, unknown], 'Authorization')).toBe(
      'Bearer access-2',
    );
  });

  it('refuses an empty key instead of sending an unprotected request', () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch');

    expect(() => apiClient.postIdempotent('/payments/payments', {}, '')).toThrow(
      /non-empty idempotency key/i,
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('leaves error behaviour unchanged', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ error: { code: 'INSUFFICIENT_FUNDS', message: 'Not enough balance.' } }, 422),
    );

    await expect(
      apiClient.postIdempotent('/payments/wallet/transfer', { amount_minor: 1 }, 'KEY-E'),
    ).rejects.toMatchObject({ status: 422, error: { code: 'INSUFFICIENT_FUNDS' } });
  });

  it('does not add a key to ordinary posts, gets or deletes', async () => {
    // A fresh Response per call: a body can only be read once, so reusing one
    // object across three requests fails on the second for the wrong reason.
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockImplementation(() => Promise.resolve(jsonResponse({ data: {} }, 200)));

    await apiClient.post('/commerce/cart/items', { product_id: 'p1', quantity: 1 });
    await apiClient.get('/commerce/cart');
    await apiClient.delete('/commerce/cart');

    for (const call of fetchMock.mock.calls) {
      expect(headerOf(call as [unknown, unknown], 'Idempotency-Key')).toBeUndefined();
    }
  });
});
