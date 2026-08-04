import { apiClient } from '@lib/apiClient';
import type {
  ChatTurn,
  Conversation,
  ConversationSummary,
  GeneratedRecipe,
  GeneratedResult,
  MealSuggestion,
  Paginated,
  SubstitutionSuggestion,
  UsageSummary,
} from './types';

export interface GenerateRecipePayload {
  dish_name: string;
  servings?: number;
  difficulty?: string;
  dietary_preferences?: string[];
  available_ingredients?: string[];
  notes?: string;
}

/** Client for the AI Engine REST endpoints (all under /ai, authenticated). */
export const aiApi = {
  generateRecipe: (payload: GenerateRecipePayload) =>
    apiClient.post<GeneratedResult<GeneratedRecipe>>('/ai/recipes/generate', payload),

  improveRecipe: (payload: { title: string; ingredients: string[]; steps: string[]; goal?: string }) =>
    apiClient.post<GeneratedResult<GeneratedRecipe>>('/ai/recipes/improve', payload),

  leftoverRecipe: (payload: { ingredients: string[]; dietary_preferences?: string[]; meal_type?: string }) =>
    apiClient.post<GeneratedResult<GeneratedRecipe>>('/ai/recipes/leftovers', payload),

  summarizeRecipe: (payload: { content: string; max_words?: number }) =>
    apiClient.post<GeneratedResult<string>>('/ai/recipes/summarize', payload),

  translateRecipe: (payload: { content: string; target_language: string }) =>
    apiClient.post<GeneratedResult<string>>('/ai/recipes/translate', payload),

  describeFood: (payload: { food_name: string; region?: string; keywords?: string[] }) =>
    apiClient.post<GeneratedResult<string>>('/ai/foods/describe', payload),

  chat: (message: string, conversationId?: string) =>
    apiClient.post<ChatTurn>('/ai/assistant/chat', {
      message,
      conversation_id: conversationId,
    }),

  cookingTips: (payload: { topic: string; skill_level?: string }) =>
    apiClient.post<GeneratedResult<string>>('/ai/assistant/tips', payload),

  substitute: (payload: { ingredient: string; reason?: string; dish_context?: string }) =>
    apiClient.post<GeneratedResult<{ substitutions?: SubstitutionSuggestion[] }>>(
      '/ai/assistant/substitute',
      payload,
    ),

  mealSuggestions: (payload: { meal_type?: string; dietary_preferences?: string[]; count?: number }) =>
    apiClient.post<GeneratedResult<{ suggestions?: MealSuggestion[] }>>('/ai/assistant/meals', payload),

  conversations: () => apiClient.getPage<Paginated<ConversationSummary>>('/ai/conversations'),

  conversation: (id: string) => apiClient.get<Conversation>(`/ai/conversations/${id}`),

  deleteConversation: (id: string) => apiClient.delete<void>(`/ai/conversations/${id}`),

  usage: (days = 30) => apiClient.get<UsageSummary>(`/ai/usage?days=${String(days)}`),
};
