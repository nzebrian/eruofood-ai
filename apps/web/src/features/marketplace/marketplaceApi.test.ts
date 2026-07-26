import { afterEach, describe, expect, it, vi } from 'vitest';
import { marketplaceApi } from './marketplaceApi';
import { formatMoney } from './types';

describe('marketplaceApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('adds an item to the cart via POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { vendor_id: 'v1', currency: 'NGN', items: [], subtotal_minor: 0 } }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await marketplaceApi.addToCart('item-1', 2);

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/cart/items');
    expect((init as RequestInit).method).toBe('POST');
    const body = JSON.parse((init as RequestInit).body as string) as { quantity: number };
    expect(body.quantity).toBe(2);
  });

  it('searches vendors with query params', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { page: 1, per_page: 20, total: 0 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await marketplaceApi.vendors({ q: 'jollof', lat: 6.5, lng: 3.3 });

    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/vendors?');
    expect(url).toContain('q=jollof');
  });

  it('formats minor units as Naira', () => {
    expect(formatMoney(250000)).toBe('₦2,500');
  });
});
