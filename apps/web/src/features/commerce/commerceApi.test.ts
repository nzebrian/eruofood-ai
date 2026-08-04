import { afterEach, describe, expect, it, vi } from 'vitest';
import { commerceApi } from './commerceApi';
import { formatMoney } from './types';

describe('commerceApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('adds a product to the cart via POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({ data: { currency: 'NGN', coupon_code: null, items: [], item_count: 0, subtotal_minor: 0 } }),
        { status: 201, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    await commerceApi.addToCart('prod-1', 3, 'sku-2');

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/commerce/cart/items');
    expect((init as RequestInit).method).toBe('POST');
    const body = JSON.parse((init as RequestInit).body as string) as { quantity: number; variant_sku: string };
    expect(body.quantity).toBe(3);
    expect(body.variant_sku).toBe('sku-2');
  });

  it('searches products with query params', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { page: 1, per_page: 20, total: 0 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await commerceApi.products({ q: 'rice', department: 'pantry' });

    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/commerce/products?');
    expect(url).toContain('q=rice');
    expect(url).toContain('department=pantry');
  });

  it('requests a checkout quote for pickup', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          data: { currency: 'NGN', subtotal_minor: 0, discount_minor: 0, tax_minor: 0, shipping_minor: 0, total_minor: 0 },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    await commerceApi.quote(true);
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/commerce/checkout/quote?pickup=true');
  });

  it('formats money in naira', () => {
    expect(formatMoney(1900000)).toBe('₦19,000.00');
  });
});
