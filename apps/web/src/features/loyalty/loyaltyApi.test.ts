import { afterEach, describe, expect, it, vi } from 'vitest';
import { loyaltyApi } from './loyaltyApi';

function mockJson(body: unknown, status = 200) {
  const hasBody = status !== 204;
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(hasBody ? JSON.stringify(body) : null, {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

describe('loyaltyApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('fetches the tier ladder', async () => {
    const fetchMock = mockJson({ data: [{ key: 'bronze' }] });
    await loyaltyApi.tiers();
    expect(fetchMock.mock.calls[0]?.[0] as string).toContain('/loyalty/tiers');
  });

  it('redeems a reward via POST', async () => {
    const fetchMock = mockJson({ data: { id: 'rd1', status: 'issued' } }, 201);
    await loyaltyApi.redeem('rw1');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/loyalty/rewards/rw1/redeem');
    expect((init as RequestInit).method).toBe('POST');
  });

  it('applies a referral code', async () => {
    const fetchMock = mockJson({ data: { status: 'pending' } }, 201);
    await loyaltyApi.applyReferral('ABCD1234');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/loyalty/referrals/apply');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ code: 'ABCD1234' });
  });

  it('adjusts points as admin', async () => {
    const fetchMock = mockJson({ data: { balance: 100 } });
    await loyaltyApi.adjust('user-1', 100, 'goodwill');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/loyalty/admin/adjust');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({ user_id: 'user-1', points: 100 });
  });

  it('fetches analytics', async () => {
    const fetchMock = mockJson({ data: { members: 5 } });
    await loyaltyApi.analytics();
    expect(fetchMock.mock.calls[0]?.[0] as string).toContain('/loyalty/admin/analytics');
  });
});
