import { apiClient } from '@lib/apiClient';
import type {
  Paginated,
  RatingSummary,
  Review,
  ReviewAnalytics,
  ReviewSort,
  SubjectType,
  SubmitReviewPayload,
} from './types';

function query(params: Record<string, string | number | boolean | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** Client for the Reviews & Ratings REST endpoints (mounted at /reviews). */
export const reviewsApi = {
  // Public read surface
  forSubject: (type: SubjectType, id: string, opts: { sort?: ReviewSort; verified?: boolean } = {}) =>
    apiClient.getPage<Paginated<Review>>(
      `/reviews/${type}/${id}${query({ sort: opts.sort, verified: opts.verified })}`,
    ),
  summary: (type: SubjectType, id: string) =>
    apiClient.get<RatingSummary>(`/reviews/${type}/${id}/summary`),

  // Customer surface
  submit: (payload: SubmitReviewPayload) => apiClient.post<Review>('/reviews', payload),
  edit: (id: string, payload: Omit<SubmitReviewPayload, 'subject_type' | 'subject_id'>) =>
    apiClient.put<Review>(`/reviews/${id}`, payload),
  vote: (id: string, helpful: boolean) => apiClient.post<Review>(`/reviews/${id}/vote`, { helpful }),
  respond: (id: string, body: string) => apiClient.post<Review>(`/reviews/${id}/response`, { body }),
  mine: () => apiClient.getPage<Paginated<Review>>('/reviews/me'),

  // Moderation
  queue: () => apiClient.getPage<Paginated<Review>>('/reviews/moderation/queue'),
  approve: (id: string) => apiClient.post<Review>(`/reviews/moderation/${id}/approve`, {}),
  reject: (id: string, reason: string) => apiClient.post<Review>(`/reviews/moderation/${id}/reject`, { reason }),
  remove: (id: string, reason: string) => apiClient.post<Review>(`/reviews/moderation/${id}/remove`, { reason }),

  // Admin analytics
  analytics: () => apiClient.get<ReviewAnalytics>('/reviews/admin/analytics'),
  topRated: (type: SubjectType, minCount = 1, limit = 10) =>
    apiClient.get<RatingSummary[]>(`/reviews/admin/top-rated/${type}${query({ min_count: minCount, limit })}`),
};
