# AI Engine — API Endpoints

Base URL: `/api/v1`. **All AI endpoints require a bearer token** (so calls can be
rate-limited and cost-attributed per user); prompt admin needs the `admin` role.
Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

Every generation response is enveloped as `{ "data": { ... } }`. Generations
carry a `meta` block:

```json
{
  "provider": "anthropic",
  "model": "claude-opus-5",
  "cached": false,
  "finish_reason": "stop",
  "tokens": { "input": 210, "output": 480, "total": 690 }
}
```

`content` is an **object** for structured features (recipe generation, improvement,
leftovers, substitutions, meal suggestions) and a **string** for text features
(summarize, translate, describe, tips).

## Recipe & content generation (authenticated)

| Method & Path | Purpose | Key body fields |
|---|---|---|
| `POST /ai/recipes/generate` | Generate a recipe | `dish_name` (req), `servings`, `difficulty`, `dietary_preferences[]`, `available_ingredients[]`, `notes` |
| `POST /ai/recipes/improve` | Improve a recipe | `title` (req), `ingredients[]` (req), `steps[]` (req), `goal` |
| `POST /ai/recipes/leftovers` | Leftover recipe | `ingredients[]` (req), `dietary_preferences[]`, `meal_type` |
| `POST /ai/recipes/summarize` | Summarise a recipe | `content` (req), `max_words` |
| `POST /ai/recipes/translate` | Translate a recipe | `content` (req), `target_language` (req) |
| `POST /ai/foods/describe` | Food description | `food_name` (req), `region`, `keywords[]` |

## Smart Cooking Assistant & helpers (authenticated)

| Method & Path | Purpose | Key body fields |
|---|---|---|
| `POST /ai/assistant/chat` | Chat (multi-turn) | `message` (req), `conversation_id` (omit to start a new thread) |
| `POST /ai/assistant/tips` | Cooking tips | `topic` (req), `skill_level` |
| `POST /ai/assistant/substitute` | Ingredient substitution | `ingredient` (req), `reason`, `dish_context`, `dietary_preferences[]` |
| `POST /ai/assistant/meals` | Meal suggestions | `meal_type`, `dietary_preferences[]`, `count`, `budget` |

`chat` returns `{ conversation_id, reply, conversation, meta }`. Pass the returned
`conversation_id` back on the next turn to continue the same thread; chat turns
are never cached.

## Chat history & usage (authenticated)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /ai/conversations` | List conversations | Paginated summaries, most-recently-updated first. |
| `GET /ai/conversations/{id}` | Read a thread | Full messages; 404 if not the caller's. |
| `PATCH /ai/conversations/{id}` | Rename | Body: `title`. |
| `DELETE /ai/conversations/{id}` | Delete | Removes the thread and its messages. |
| `GET /ai/usage?days=30` | Usage & cost | Rolling requests, cached count, tokens, and USD cost. |

## Prompt management (role: admin)

| Method & Path | Purpose | Explanation |
|---|---|---|
| `GET /admin/ai/prompts?feature=` | List versions | Every version of a feature's prompt, newest first. |
| `POST /admin/ai/prompts` | Publish a version | Body: `feature`, `name`, `system_template`, `user_template`, `variables[]`, `model?`, `activate`. Increments the version; activating deactivates the others. |
| `POST /admin/ai/prompts/{id}/activate` | Activate a version | Roll the active version to an existing template (rollback/forward). |

## Errors

| Code | HTTP | Meaning |
|---|---|---|
| `AI_PROMPT_NOT_FOUND` | 404 | No active prompt seeded for the feature. |
| `AI_CONVERSATION_NOT_FOUND` | 404 | Unknown conversation, or not the caller's. |
| `AI_RATE_LIMIT_EXCEEDED` | 429 | Per-user AI quota exceeded. |
| `AI_GENERATION_FAILED` | 502 | Provider call failed / response unparseable. |
| `AI_PROVIDER_UNAVAILABLE` | 503 | No configured provider could serve the request. |
| `INVALID_ARGUMENT` / validation | 422 | Bad request body. |
