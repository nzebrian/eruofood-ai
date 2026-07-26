import { apiClient } from '@lib/apiClient';
import type {
  Assessment,
  DailySummary,
  HealthProfile,
  MealPlan,
  NutritionAdvice,
  ProgressEntry,
  ShoppingList,
} from './types';

export interface HealthProfilePayload {
  weight_kg: number;
  height_cm: number;
  age: number;
  gender: string;
  activity_level: string;
  goal: string;
  dietary_preferences?: string[];
  allergies?: string[];
  medical_restrictions?: string[];
}

/** Client for the Nutrition, Health & Personalisation REST endpoints. */
export const nutritionApi = {
  getProfile: () => apiClient.get<HealthProfile | null>('/nutrition/profile'),
  saveProfile: (payload: HealthProfilePayload) =>
    apiClient.put<HealthProfile>('/nutrition/profile', payload),

  assessment: () => apiClient.get<Assessment>('/nutrition/assessment'),
  calculate: (payload: HealthProfilePayload) => apiClient.post<Assessment>('/nutrition/calculate', payload),

  diaryDay: (date: string) => apiClient.get<DailySummary>(`/nutrition/diary?date=${date}`),
  logDiary: (payload: Record<string, unknown>) => apiClient.post('/nutrition/diary', payload),
  deleteDiary: (id: string) => apiClient.delete<void>(`/nutrition/diary/${id}`),

  mealPlans: () => apiClient.getPage<{ data: MealPlan[]; meta: unknown }>('/nutrition/meal-plans'),
  createPlan: (payload: Record<string, unknown>) => apiClient.post<MealPlan>('/nutrition/meal-plans', payload),
  plan: (id: string) => apiClient.get<MealPlan>(`/nutrition/meal-plans/${id}`),
  adjustPlan: (id: string, factor: number) =>
    apiClient.post<MealPlan>(`/nutrition/meal-plans/${id}/adjust`, { factor }),
  shoppingList: (id: string) => apiClient.get<ShoppingList>(`/nutrition/meal-plans/${id}/shopping-list`),
  deletePlan: (id: string) => apiClient.delete<void>(`/nutrition/meal-plans/${id}`),

  progress: () => apiClient.get<ProgressEntry[]>('/nutrition/progress'),
  recordProgress: (payload: { date: string; weight_kg: number; note?: string }) =>
    apiClient.post<ProgressEntry>('/nutrition/progress', payload),

  mealRecommendations: () => apiClient.get<NutritionAdvice>('/nutrition/recommendations/meals'),
  weeklyInsights: () => apiClient.get<NutritionAdvice>('/nutrition/recommendations/weekly-insights'),
};
