import { afterEach, describe, expect, it, vi } from 'vitest';
import { paymentsApi } from './paymentsApi';
import { formatMoney } from './types';

describe('paymentsApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('initiates a payment via POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          data: { payment_id: 'p1', reference: 'PMT-1', status: 'succeeded', provider: 'mock', authorization_url: null },
        }),
        { status: 201, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    await paymentsApi.initiate({ amount_minor: 1000000, customer_email: 'a@b.co' });

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/payments/payments');
    expect((init as RequestInit).method).toBe('POST');
    const body = JSON.parse((init as RequestInit).body as string) as { amount_minor: number };
    expect(body.amount_minor).toBe(1000000);
  });

  it('tops up the wallet via POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { payment_id: 'p2', reference: 'PMT-2', status: 'succeeded', provider: 'mock', authorization_url: null } }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await paymentsApi.topUp(500000, 'a@b.co');
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/payments/wallet/topup');
  });

  it('formats naira amounts', () => {
    expect(formatMoney(1000000)).toBe('₦10,000.00');
  });
});
