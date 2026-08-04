# Nutrition, Health & Personalisation — API Endpoints

Base URL: `/api/v1`. The nutrition database and the ad-hoc calculators are
public; everything tied to a user (profile, diary, plans, progress,
personalisation) needs a bearer token; the nutrition-database admin needs the
`admin` role. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Nutrition database (public)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /nutrition/items?q=&category=` | Search foods | Paginated nutrition items with full panels. |
| `GET /nutrition/items/{id}` | Item detail | One item's serving size + nutrition. |
| `POST /nutrition/calculate` | Ad-hoc calculator | Body: weight/height/age/gender (+activity, goal). Returns BMI/BMR/TDEE/target/macros. |
| `POST /nutrition/analyse` | Meal / recipe breakdown | Body: `items[]` of `{nutrition_item_id, servings}`. Returns totals + per-item facts. |

## Health profile & assessment (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /nutrition/profile` | Read profile | The caller's profile, or `data: null`. |
| `PUT /nutrition/profile` | Save profile | Create/update weight, height, age, gender, activity, goal, preferences, allergies, restrictions. |
| `GET /nutrition/assessment` | Assess profile | BMI/BMR/TDEE/calorie target/macros for the saved profile. `422` if no profile. |

## Daily nutrient tracking (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /nutrition/diary?date=YYYY-MM-DD` | A day's diary | Entries, summed totals, targets, and remaining calories (defaults to today). |
| `POST /nutrition/diary` | Log a food | Reference an item (`nutrition_item_id` + `servings`) or a custom food (`item_name` + `nutrition`). |
| `DELETE /nutrition/diary/{id}` | Remove an entry | Owner only. |

## Meal planning (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /nutrition/meal-plans` | List plans | Paginated, newest first. |
| `POST /nutrition/meal-plans` | Create a plan | Daily/weekly/monthly with `entries[]`. |
| `GET /nutrition/meal-plans/{id}` | Plan detail | Owner only. |
| `POST /nutrition/meal-plans/{id}/adjust` | Portion adjustment | Body: `factor` — scales every entry's servings (and cost). |
| `GET /nutrition/meal-plans/{id}/shopping-list` | Shopping list | Merged items + total estimated cost. |
| `DELETE /nutrition/meal-plans/{id}` | Delete a plan | Owner only. |

## Progress tracking (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /nutrition/progress` | Weight history | Newest first. |
| `POST /nutrition/progress` | Record weight | Body: `date`, `weight_kg`, optional `note`. |

## AI personalisation (authenticated, throttled)

| Method & Path | Purpose |
|---|---|
| `GET /nutrition/recommendations/meals` | Personalised day of meals. |
| `GET /nutrition/recommendations/suggestions?date=` | Smart suggestions for the rest of today. |
| `GET /nutrition/recommendations/diet-improvement` | Diet improvement tips. |
| `GET /nutrition/recommendations/weekly-insights` | Weekly nutrition & progress insights. |

Each returns `{ advice, meta: { provider, model, cached } }`. All require a saved
profile (`422` otherwise). Requests flow through the AI Engine's published
contract, so they benefit from provider fallback, caching and usage logging.

## Nutrition database admin (role: admin)

| Method & Path | Purpose |
|---|---|
| `POST /admin/nutrition/items` | Create a nutrition item. |
| `PUT /admin/nutrition/items/{id}` | Update a nutrition item. |
| `DELETE /admin/nutrition/items/{id}` | Delete a nutrition item. |

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `NUTRITION_RESOURCE_NOT_FOUND` | 404 | Item, plan or entry missing / not the caller's. |
| `NUTRITION_PROFILE_INCOMPLETE` | 422 | A saved health profile is required. |
| `INVALID_ARGUMENT` / validation | 422 | Bad request body (e.g. duplicate item slug, implausible values). |
