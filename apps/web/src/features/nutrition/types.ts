/** Types for the Nutrition, Health & Personalisation feature slice. */

export interface MacroTargets {
  protein_grams: number;
  carb_grams: number;
  fat_grams: number;
}

export interface Assessment {
  bmi: number;
  bmi_category: string;
  bmr: number;
  tdee: number;
  calorie_target: number;
  macro_targets: MacroTargets;
}

export interface NutritionFacts {
  calories: number;
  protein_grams: number;
  carb_grams: number;
  fat_grams: number;
  fibre_grams: number;
  sugar_grams: number;
  sodium_mg: number;
  cholesterol_mg: number;
  water_ml: number;
  micronutrients: Record<string, number>;
}

export type Gender = 'male' | 'female' | 'other';
export type ActivityLevel = 'sedentary' | 'light' | 'moderate' | 'active' | 'very_active';
export type HealthGoal = 'lose_weight' | 'maintain' | 'gain_weight' | 'gain_muscle';
export type PlanPeriod = 'daily' | 'weekly' | 'monthly';
export type MealType = 'breakfast' | 'lunch' | 'dinner' | 'snack';

export interface HealthProfile {
  weight_kg: number;
  height_cm: number;
  age: number;
  gender: Gender;
  activity_level: ActivityLevel;
  goal: HealthGoal;
  dietary_preferences: string[];
  allergies: string[];
  medical_restrictions: string[];
  updated_at: string;
}

export interface DiaryEntry {
  id: string;
  date: string;
  meal_type: MealType;
  item_name: string;
  servings: number;
  nutrition: NutritionFacts;
}

export interface DailySummary {
  date: string;
  entries: DiaryEntry[];
  totals: NutritionFacts;
  targets: Assessment | null;
  remaining_calories: number | null;
}

export interface MealPlanEntry {
  date: string;
  meal_type: MealType;
  label: string;
  servings: number;
  nutrition_item_id: string | null;
  estimated_cost: number | null;
}

export interface MealPlan {
  id: string;
  title: string;
  period: PlanPeriod;
  start_date: string;
  entries: MealPlanEntry[];
  estimated_cost: number;
  created_at: string;
}

export interface ShoppingList {
  items: { name: string; servings: number; estimated_cost: number | null }[];
  total_estimated_cost: number;
}

export interface ProgressEntry {
  id: string;
  date: string;
  weight_kg: number;
  note: string | null;
}

export interface NutritionAdvice {
  advice: string;
  meta: { provider: string; model: string; cached: boolean };
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}
