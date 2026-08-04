import { afterEach, describe, expect, it, vi } from 'vitest';
import { catalogApi } from './catalogApi';

describe('catalogApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('requests foods with filter query params and returns the paginated envelope', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { page: 1, per_page: 20, total: 0 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const page = await catalogApi.foods({ q: 'jollof', region: 'south_west' });

    expect(page.meta.total).toBe(0);
    const calledUrl = fetchMock.mock.calls[0]?.[0] as string;
    expect(calledUrl).toContain('/foods?');
    expect(calledUrl).toContain('q=jollof');
    expect(calledUrl).toContain('region=south_west');
  });
});
