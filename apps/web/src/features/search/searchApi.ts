import { apiClient } from '@lib/apiClient';
import type { SearchDocument, SearchFilters, SearchResults } from './types';

function query(params: Record<string, string | number | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** Client for the Search REST endpoints (mounted at /search). */
export const searchApi = {
  search: (term: string, type: string, sort: string, filters: SearchFilters, page = 1) =>
    apiClient.get<SearchResults>(
      `/search${query({ q: term, type, sort, page, ...filters })}`,
    ),

  autocomplete: (term: string, type: string) =>
    apiClient.get<{ suggestions: string[] }>(`/search/autocomplete${query({ q: term, type })}`),

  trending: () => apiClient.get<{ trending: string[] }>('/search/trending'),

  recommendations: (kind: string, type: string, anchorId?: string, limit = 8) =>
    apiClient.get<{ kind: string; items: SearchDocument[] }>(
      `/search/recommendations${query({ kind, type, anchor_id: anchorId, limit })}`,
    ),

  recordClick: (queryId: string, documentId: string, position: number, fromRecommendation = false) =>
    apiClient.post<void>('/search/click', {
      query_id: queryId,
      document_id: documentId,
      position,
      from_recommendation: fromRecommendation,
    }),
};
