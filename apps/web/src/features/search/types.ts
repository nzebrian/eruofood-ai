/** Types for the Search, Discovery & Recommendation module. */

export interface SearchDocument {
  id: string;
  type: string;
  source_id: string;
  title: string;
  description: string;
  url: string | null;
  image: string | null;
  region: string | null;
  cuisine: string | null;
  category: string | null;
  rating: number;
  popularity: number;
  price_minor: number | null;
  prep_time_minutes: number | null;
  difficulty: string | null;
  tags: string[];
}

export interface SearchHit {
  document: SearchDocument;
  score: number;
  lexical_score: number;
  semantic_score: number;
  distance_km: number | null;
  highlight: string | null;
}

export interface SearchResults {
  query_id: string | null;
  total: number;
  page: number;
  per_page: number;
  facets: Record<string, Record<string, number>>;
  hits: SearchHit[];
}

export interface SearchFilters {
  region?: string;
  cuisine?: string;
  category?: string;
  difficulty?: string;
  min_rating?: number;
  max_price?: number;
  max_cooking_time?: number;
  dietary?: string;
}

export const SEARCH_TYPES = [
  'global',
  'recipe',
  'food',
  'restaurant',
  'vendor',
  'product',
] as const;

export const SORT_OPTIONS = [
  'relevance',
  'popularity',
  'rating',
  'newest',
  'price',
  'prep_time',
  'distance',
] as const;

export const RECOMMENDATION_KINDS = [
  'trending',
  'seasonal',
  'restaurant',
  'related',
] as const;

/** Format a minor-unit price as Naira. */
export function formatPrice(minor: number | null): string {
  if (minor === null) return '';
  return `₦${(minor / 100).toLocaleString('en-NG', { maximumFractionDigits: 0 })}`;
}
