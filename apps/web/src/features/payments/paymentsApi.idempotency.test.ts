import { afterEach, describe, expect, it, vi } from 'vitest';
import { commerceApi } from '../commerce/commerceApi';
import { marketplaceApi } from '../marketplace/marketplaceApi';
import { paymentsApi } from './paymentsApi';

/**
 * M43 — every first-party web operation that moves money carries a key, and
 * nothing else does.
 *
 * The two failure modes this has to separate are opposite in shape. Too few
 * keys and a duplicate submission charges twice. Too many — one key reused
 * across unrelated operations — and the server replays the first result for the
 * second request, so the second operation silently never happens while the
 * caller is told it succeeded. Both are tested here.
 */

function ok(): Response {
  return new Response(JSON.stringify({ data: { id: 'x' } }), {
    status: 201,
    headers: { 'Content-Type': 'application/json' },
  });
}

function mockFetch() {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(ok()));
}

function keysSent(mock: { mock: { calls: unknown[][] } }): (string | undefined)[] {
  return mock.mock.calls.map((call) => {
    const init = call[1] as RequestInit | undefined;

    return (init?.headers as Record<string, string> | undefined)?.['Idempotency-Key'];
  });
}

/** Every money-moving call on the three first-party clients, in one place. */
const moneyMoving: { name: string; send: () => Promise<unknown> }[] = [
  {
    name: 'payments.initiate',
    send: () => paymentsApi.initiate({ amount_minor: 1000, customer_email: 'a@b.co' }),
  },
  { name: 'payments.wallet.topup', send: () => paymentsApi.topUp(5000, 'a@b.co') },
  { name: 'payments.wallet.transfer', send: () => paymentsApi.transfer('user-2', 2500, 'note') },
  { name: 'payments.refund', send: () => paymentsApi.refund('pay-1', 'duplicate charge') },
  { name: 'commerce.checkout', send: () => commerceApi.checkout({ pickup: true }) },
  {
    name: 'marketplace.checkout',
    send: () => marketplaceApi.checkout({ fulfilment: 'pickup' }),
  },
];

describe('web money-moving operations', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it.each(moneyMoving)('$name sends an Idempotency-Key', async ({ send }) => {
    const fetchMock = mockFetch();

    await send();

    const [key] = keysSent(fetchMock);
    expect(key).toBeDefined();
    expect(key).toMatch(/^[0-9a-f-]{36}$/i);
  });

  it('gives every operation, of every kind, its own key', async () => {
    // Cross-operation isolation. If any two of these shared a value the second
    // would be answered with the first one's stored response.
    const fetchMock = mockFetch();

    for (const { send } of moneyMoving) {
      await send();
    }

    const keys = keysSent(fetchMock);

    expect(keys).toHaveLength(moneyMoving.length);
    expect(new Set(keys).size).toBe(moneyMoving.length);
  });

  it('gives two invocations of the SAME operation different keys', async () => {
    // A second, deliberate checkout is a second order. Reusing the key here
    // would make the platform silently drop it.
    const fetchMock = mockFetch();

    await commerceApi.checkout({ pickup: true });
    await commerceApi.checkout({ pickup: true });

    const [first, second] = keysSent(fetchMock);

    expect(first).toBeDefined();
    expect(second).toBeDefined();
    expect(second).not.toBe(first);
  });

  it('reuses a caller-supplied key so an identical re-send replays', async () => {
    // The other direction: a caller that is retrying the SAME payload passes
    // the key it already used, and the server replays rather than re-charging.
    const fetchMock = mockFetch();

    await commerceApi.checkout({ pickup: true }, 'HELD-KEY');
    await commerceApi.checkout({ pickup: true }, 'HELD-KEY');

    expect(keysSent(fetchMock)).toEqual(['HELD-KEY', 'HELD-KEY']);
  });

  it('does not key the read-only and non-financial calls', async () => {
    // Repeating any of these cannot move money, and a key on them would consume
    // a claim row for nothing. `advanceOrder` changes a status, not a balance.
    const fetchMock = mockFetch();

    await paymentsApi.wallet();
    await paymentsApi.payments();
    await paymentsApi.methods();
    await paymentsApi.makeDefault('m1');
    await commerceApi.cart();
    await commerceApi.addToCart('p1', 1);
    await commerceApi.applyCoupon('SAVE10');
    await marketplaceApi.addToCart('m1', 1);
    await marketplaceApi.advanceOrder('o1', 'accepted');

    expect(keysSent(fetchMock).every((k) => k === undefined)).toBe(true);
  });

  it('keeps the request payloads exactly as they were', async () => {
    // M43 adds a header and nothing else. Amounts, recipients and reasons must
    // reach the API unchanged.
    const fetchMock = mockFetch();

    await paymentsApi.transfer('user-2', 2500, 'rent');

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect(JSON.parse(init.body as string)).toEqual({
      to_user_id: 'user-2',
      amount_minor: 2500,
      note: 'rent',
    });
  });

  it('never writes the key to the console', async () => {
    // A key in a log is a key in a bug report, a session replay and an error
    // aggregator. Nothing in this path may print it.
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((level) =>
      vi.spyOn(console, level).mockImplementation(() => undefined),
    );
    const fetchMock = mockFetch();

    for (const { send } of moneyMoving) {
      await send();
    }

    const printed = spies.flatMap((spy) => spy.mock.calls.flat().map((arg) => String(arg)));

    for (const key of keysSent(fetchMock)) {
      expect(printed.some((line) => key !== undefined && line.includes(key))).toBe(false);
    }
  });
});
