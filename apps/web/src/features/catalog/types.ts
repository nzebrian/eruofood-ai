// Catalog types — mirror packages/api-contracts/openapi.yaml (generated later).

export interface Category {
  id: string;
  name: string;
  slug: string;
  type: string;
  description: string | null;
  sort_order: number;
  active: boolean;
}

export interface LocalName {
  name: string;
  language: string;
}

export interface Nutrition {
  calories: number;
  protein_grams: number;
  carbohydrate_grams: number;
  fat_grams: number;
  fiber_grams: number;
  basis: string;
}

export interface FoodSummary {
  id: string;
  name: string;
  slug: string;
  category_id: string;
  region: string;
  tags: string[];
  status: string;
  primary_image: string | null;
}

export interface Food extends FoodSummary {
  description: string | null;
  states: string[];
  local_names: LocalName[];
  nutrition: Nutrition | null;
  images: string[];
  video_url: string | null;
}

export interface RecipeSummary {
  id: string;
  food_id: string;
  title: string;
  slug: string;
  summary: string | null;
  difficulty: 'easy' | 'medium' | 'hard';
  prep_time_minutes: number;
  cook_time_minutes: number;
  total_time_minutes: number;
  serving_size: number;
  rating_average: number;
  rating_count: number;
  tags: string[];
  status: string;
}

export interface RecipeStep {
  order: number;
  instruction: string;
  image_url: string | null;
  duration_minutes: number | null;
}

export interface RecipeIngredientLine {
  name: string;
  amount: number;
  unit: string;
  ingredient_id: string | null;
  note: string | null;
}

export interface Recipe extends RecipeSummary {
  author_id: string;
  version: number;
  ingredients: RecipeIngredientLine[];
  steps: RecipeStep[];
  tips: string[];
  related_recipe_ids: string[];
  is_favourited: boolean;
}

export interface RecipeReview {
  id: string;
  recipe_id: string;
  user_id: string;
  rating: number;
  comment: string | null;
  created_at: string;
}

export interface PageMeta {
  page: number;
  per_page: number;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}

export interface FoodFilters {
  q?: string;
  category_id?: string;
  region?: string;
  tag?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}
