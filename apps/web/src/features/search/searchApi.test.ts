import { afterEach, describe, expect, it, vi } from 'vitest';
import { searchApi } from './searchApi';

function mockJson(body: unknown, status = 200) {
  const hasBody = status !== 204 && status !== 205 && status !== 304;
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(hasBody ? JSON.stringify(body) : null, {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

describe('searchApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('builds a search request with term, type, sort and filters', async () => {
    const fetchMock = mockJson({ data: { total: 0, hits: [], facets: {}, query_id: 'q1' } });
    await searchApi.search('jollof', 'food', 'rating', { region: 'South West', min_rating: 4 }, 2);
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/search');
    expect(url).toContain('q=jollof');
    expect(url).toContain('type=food');
    expect(url).toContain('sort=rating');
    expect(url).toContain('region=South+West');
    expect(url).toContain('min_rating=4');
    expect(url).toContain('page=2');
  });

  it('requests autocomplete suggestions', async () => {
    const fetchMock = mockJson({ data: { suggestions: ['Jollof Rice'] } });
    await searchApi.autocomplete('jol', 'global');
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/search/autocomplete');
    expect(url).toContain('q=jol');
  });

  it('records a click via POST', async () => {
    const fetchMock = mockJson({}, 204);
    await searchApi.recordClick('q1', 'food:1', 0, true);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/search/click');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({
      query_id: 'q1',
      document_id: 'food:1',
      from_recommendation: true,
    });
  });

  it('fetches recommendations by kind', async () => {
    const fetchMock = mockJson({ data: { kind: 'trending', items: [] } });
    await searchApi.recommendations('trending', 'food');
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/search/recommendations');
    expect(url).toContain('kind=trending');
  });
});
