/** Types for the AI Engine feature slice (recipe generation, assistant, usage). */

export interface AiMeta {
  provider: string;
  model: string;
  cached: boolean;
  tokens: { input: number; output: number; total: number };
  finish_reason: string;
}

/** A structured or text generation result plus its provenance metadata. */
export interface GeneratedResult<T> {
  content: T;
  meta: AiMeta;
}

/** The (loosely typed) recipe shape the model returns for generation features. */
export interface GeneratedRecipe {
  title?: string;
  summary?: string;
  servings?: number;
  difficulty?: string;
  ingredients?: string[];
  steps?: string[];
  tips?: string[];
  changes?: string[];
}

export interface MealSuggestion {
  name: string;
  description: string;
  key_ingredients?: string[];
}

export interface SubstitutionSuggestion {
  substitute: string;
  ratio?: string;
  notes?: string;
}

export type ChatRole = 'system' | 'user' | 'assistant';

export interface ChatMessage {
  role: ChatRole;
  content: string;
  created_at: string;
}

export interface ConversationSummary {
  id: string;
  title: string;
  feature: string;
  message_count: number;
  created_at: string;
  updated_at: string;
}

export interface Conversation extends ConversationSummary {
  messages: ChatMessage[];
}

export interface ChatTurn {
  conversation_id: string;
  reply: string;
  conversation: Conversation;
  meta: AiMeta;
}

export interface UsageSummary {
  requests: number;
  cached_requests: number;
  input_tokens: number;
  output_tokens: number;
  total_tokens: number;
  cost_usd: number;
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}
