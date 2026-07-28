import { afterEach, describe, expect, it, vi } from 'vitest';
import { reviewsApi } from './reviewsApi';

function mockJson(body: unknown, status = 200) {
  const hasBody = status !== 204;
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(hasBody ? JSON.stringify(body) : null, {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

describe('reviewsApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('lists reviews for a subject with sort and verified filters', async () => {
    const fetchMock = mockJson({ data: [], meta: { page: 1, per_page: 20, total: 0 } });
    await reviewsApi.forSubject('vendor', 'v1', { sort: 'helpful', verified: true });
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/reviews/vendor/v1');
    expect(url).toContain('sort=helpful');
    expect(url).toContain('verified=true');
  });

  it('fetches the rating summary', async () => {
    const fetchMock = mockJson({ data: { count: 3, average: 4.5 } });
    await reviewsApi.summary('product', 'p1');
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/reviews/product/p1/summary');
  });

  it('submits a review via POST', async () => {
    const fetchMock = mockJson({ data: { id: 'r1', status: 'published' } }, 201);
    await reviewsApi.submit({ subject_type: 'vendor', subject_id: 'v1', rating: 5, title: 'Great' });
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/reviews');
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toMatchObject({ rating: 5, subject_type: 'vendor' });
  });

  it('votes a review helpful', async () => {
    const fetchMock = mockJson({ data: { helpful_yes: 1 } });
    await reviewsApi.vote('r1', true);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/reviews/r1/vote');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ helpful: true });
  });

  it('approves a review in the moderation queue', async () => {
    const fetchMock = mockJson({ data: { id: 'r1', status: 'published' } });
    await reviewsApi.approve('r1');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/reviews/moderation/r1/approve');
    expect((init as RequestInit).method).toBe('POST');
  });

  it('rejects a review with a reason', async () => {
    const fetchMock = mockJson({ data: { id: 'r1', status: 'rejected' } });
    await reviewsApi.reject('r1', 'spam');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/reviews/moderation/r1/reject');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ reason: 'spam' });
  });

  it('fetches admin analytics', async () => {
    const fetchMock = mockJson({ data: { published: 10, average: 4.2 } });
    await reviewsApi.analytics();
    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toContain('/reviews/admin/analytics');
  });
});
