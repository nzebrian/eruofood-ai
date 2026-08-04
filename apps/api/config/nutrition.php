<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Nutrition, Health & Personalisation module configuration
|------------------------------------------------------------------------------
| Tunables for the nutrition calculators and personalisation. The physiological
| constants live here (not hard-coded in the domain) so they can be reviewed and
| adjusted without a code change, and documented in one place.
*/

return [
    // Default page size for nutrition-item / plan listings.
    'pagination' => [
        'per_page' => (int) env('NUTRITION_PER_PAGE', 20),
        'max_per_page' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity multipliers (applied to BMR to obtain TDEE).
    |--------------------------------------------------------------------------
    | Standard Harris-Benedict / Mifflin activity factors.
    */
    'activity_factors' => [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
        'very_active' => 1.9,
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily calorie adjustment (kcal) applied to TDEE per health goal.
    |--------------------------------------------------------------------------
    */
    'goal_calorie_adjustment' => [
        'lose_weight' => -500,
        'maintain' => 0,
        'gain_weight' => 500,
        'gain_muscle' => 250,
    ],

    // Never recommend fewer calories than this floor.
    'min_calories' => (int) env('NUTRITION_MIN_CALORIES', 1200),

    /*
    |--------------------------------------------------------------------------
    | Macronutrient split (% of calories) per health goal: [protein, carbs, fat].
    |--------------------------------------------------------------------------
    */
    'macro_split' => [
        'lose_weight' => ['protein' => 35, 'carbs' => 30, 'fat' => 35],
        'maintain' => ['protein' => 30, 'carbs' => 40, 'fat' => 30],
        'gain_weight' => ['protein' => 25, 'carbs' => 50, 'fat' => 25],
        'gain_muscle' => ['protein' => 35, 'carbs' => 40, 'fat' => 25],
    ],
];
