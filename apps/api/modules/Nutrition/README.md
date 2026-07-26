# Nutrition Module (`EruoFood\Nutrition`)

The **Nutrition, Health & Personalisation** bounded context. It owns a nutrition
database, per-user health profiles, the nutrition calculators, daily tracking,
meal planning, progress tracking, and AI-powered personalisation — built with
the same Clean Architecture / DDD / Repository-Pattern / Service-Layer / DI
conventions as the other modules.

## What it owns

- **Nutrition database** — `NutritionItem` records (Nigerian foods) with a
  reference serving and a full `NutritionFacts` panel: calories, macronutrients
  (protein/carbs/fat), fibre, sugar, sodium, cholesterol, water, and an open map
  of micronutrients (vitamins & minerals).
- **Health profiles** — weight, height, age, gender, activity level, health
  goal, dietary preferences, allergies and medical restrictions (one per user).
- **Calculators** — BMI, BMR, TDEE, daily calorie target and macro split.
- **Daily nutrient tracking** — a food diary with per-day totals vs targets.
- **Meal & recipe analysis** — sum the nutrition of a set of items.
- **Meal planning** — daily/weekly/monthly plans, portion adjustment, shopping
  list generation, and per-entry cost roll-up (budget-friendly planning).
- **Progress tracking** — dated weight measurements.
- **AI personalisation** — meal recommendations, smart suggestions, diet
  improvement and weekly insights, via the AI module's published contract.

## Folder structure

```
modules/Nutrition/src/
├── Domain/                     # Pure PHP — no framework
│   ├── Enum/                   # Gender, ActivityLevel, HealthGoal, MealType, PlanPeriod
│   ├── ValueObject/            # NutritionFacts, MacroTargets, ServingSize, NutritionAssessment
│   ├── Service/                # NutritionCalculator (+ CalculatorSettings) — the maths
│   ├── Health/                 # HealthProfile aggregate + repo port
│   ├── Item/                   # NutritionItem aggregate + repo port
│   ├── Diary/                  # DiaryEntry aggregate + repo port
│   ├── Plan/                   # MealPlan aggregate, MealPlanEntry, repo port
│   ├── Progress/               # ProgressEntry aggregate + repo port
│   ├── Event/                  # HealthProfileUpdated
│   └── Exception/              # NutritionNotFound, ProfileNotConfigured
├── Application/                # Use cases + ports
│   ├── Port/                   # NutritionAdvisor (AI personalisation)
│   ├── Input/                  # One typed input per use case (fromArray)
│   ├── DTO/                    # DailyNutritionSummary, MealAnalysis, ShoppingList, NutritionAdvice
│   └── Service/                # HealthProfile, Calculator, Item, Diary, MealAnalysis,
│                               #   MealPlan, ShoppingList, Progress, Recommendation, Presenter
├── Infrastructure/             # Adapters
│   ├── Persistence/            # Eloquent models, repositories, 5 migrations
│   ├── Advisor/                # AiNutritionAdvisor (bridges to the AI contract)
│   ├── Seeder/                 # NutritionItemSeeder (Nigerian foods)
│   └── Provider/               # NutritionServiceProvider (composition root)
└── Interface/                  # HTTP delivery (controllers, requests, routes)
```

## The calculations

All maths lives in the pure `NutritionCalculator` domain service, driven by
`CalculatorSettings` (from `config/nutrition.php`), so the numbers are auditable
and unit-tested:

| Metric | Formula |
|---|---|
| **BMI** | weight(kg) / height(m)² — WHO categories (`<18.5` under, `<25` normal, `<30` over, else obese) |
| **BMR** | **Mifflin-St Jeor**: `10·kg + 6.25·cm − 5·age + s`; s = +5 (male), −161 (female), −78 (other = average) |
| **TDEE** | BMR × activity factor (sedentary 1.2 → very active 1.9) |
| **Calorie target** | TDEE + goal adjustment (lose −500, maintain 0, gain +500, muscle +250), floored at 1200 |
| **Macro targets** | calories split by goal (e.g. maintain 30/40/30) → grams (protein & carbs ÷4, fat ÷9) |

## AI personalisation — cross-module integration

Personalisation goes through the `NutritionAdvisor` application port, implemented
by `AiNutritionAdvisor`, which calls the **AI module's published contract**
`EruoFood\Ai\Contracts\AiAdvisor` (never AI internals). The AI Engine handles
provider selection, caching, rate-limiting and cost/usage logging (attributing
these calls to the neutral `external_advice` feature). This keeps the two
contexts decoupled per the Modular Monolith rule, and — because the AI module
uses the mock provider in tests — the whole personalisation path runs offline.

## Data ownership

The module owns its own nutrition data; it references other contexts only by ID
(soft references — e.g. `user_id` → Identity, optional `food_id` → Catalog) and
never joins across their tables. Diary entries snapshot the nutrition consumed,
so historical totals never change when an item is later edited.

Seed the nutrition database:

```
php artisan db:seed --class="EruoFood\Nutrition\Infrastructure\Seeder\NutritionItemSeeder"
```

## Error → HTTP mapping

`NUTRITION_RESOURCE_NOT_FOUND` → 404, `NUTRITION_PROFILE_INCOMPLETE` → 422
(calculations/personalisation need a saved profile), validation → 422.

## Testing

- **Unit** — the calculator (BMI/BMR/TDEE/targets against known values),
  `NutritionFacts` (scale/add), the health profile guards, and meal-plan
  portion maths.
- **Feature** — profile + assessment, diary tracking vs targets, meal plans +
  shopping list, the seeded nutrition database + analysis, and AI personalisation
  end-to-end (through the AI contract, mock provider).

See [docs/api/nutrition-endpoints.md](../../../../docs/api/nutrition-endpoints.md)
and [ADR-0005](../../../../docs/adr/0005-nutrition-calculations-and-ai-personalisation.md).
