import { apiClient } from '@lib/apiClient';
import type {
  Category,
  Food,
  FoodFilters,
  FoodSummary,
  Paginated,
  Recipe,
  RecipeReview,
  RecipeSummary,
} from './types';

function query(params: Record<string, string | number | undefined>): string {
  const q = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') q.set(key, String(value));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

export const catalogApi = {
  categories: () => apiClient.get<Category[]>('/categories'),

  foods: (filters: FoodFilters) =>
    apiClient.getPage<Paginated<FoodSummary>>(`/foods${query({ ...filters })}`),

  food: (slug: string) => apiClient.get<Food>(`/foods/${slug}`),

  recipesForFood: (foodId: string) =>
    apiClient.getPage<Paginated<RecipeSummary>>(`/foods/${foodId}/recipes`),

  recipes: (filters: Record<string, string | number | undefined>) =>
    apiClient.getPage<Paginated<RecipeSummary>>(`/recipes${query(filters)}`),

  recipe: (slug: string) => apiClient.get<Recipe>(`/recipes/${slug}`),

  recipeReviews: (id: string) =>
    apiClient.getPage<Paginated<RecipeReview>>(`/recipes/${id}/reviews`),

  submitReview: (id: string, rating: number, comment: string | null) =>
    apiClient.post<RecipeReview>(`/recipes/${id}/reviews`, { rating, comment }),

  favourites: () => apiClient.getPage<Paginated<RecipeSummary>>('/me/favourites'),

  addFavourite: (recipeId: string) =>
    apiClient.post<{ message: string }>(`/me/favourites/${recipeId}`),

  removeFavourite: (recipeId: string) => apiClient.delete<void>(`/me/favourites/${recipeId}`),
};
