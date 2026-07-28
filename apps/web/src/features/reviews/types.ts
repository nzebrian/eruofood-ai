/** Types for the Reviews & Ratings module. */

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export type SubjectType = 'product' | 'food' | 'recipe' | 'vendor' | 'restaurant' | 'rider';

export type ReviewStatus = 'pending' | 'published' | 'rejected' | 'removed';

export interface OwnerResponse {
  responder_id: string;
  body: string;
  responded_at: string;
}

export interface Review {
  id: string;
  subject_type: SubjectType;
  subject_id: string;
  author_id: string;
  rating: number;
  title: string | null;
  body: string | null;
  photos: string[];
  verified_purchase: boolean;
  status: ReviewStatus;
  helpful_yes: number;
  helpful_no: number;
  owner_response: OwnerResponse | null;
  created_at: string;
  updated_at: string;
  // Present only on the moderation view.
  moderated_by?: string | null;
  moderation_reason?: string | null;
}

export interface RatingSummary {
  subject_type: SubjectType;
  subject_id: string;
  count: number;
  average: number;
  distribution: Record<string, number>;
  verified_count: number;
  updated_at: string;
}

export interface ReviewAnalytics {
  status_counts: Record<string, number>;
  published: number;
  verified: number;
  verified_rate: number;
  distribution: Record<string, number>;
  average: number;
  by_subject_type: Record<string, number>;
}

export interface SubmitReviewPayload {
  subject_type: SubjectType;
  subject_id: string;
  rating: number;
  title?: string;
  body?: string;
  photos?: string[];
}

export const REVIEW_SORTS = ['newest', 'oldest', 'helpful', 'rating_desc', 'rating_asc'] as const;

export type ReviewSort = (typeof REVIEW_SORTS)[number];
