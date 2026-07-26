# AI Engine Module (`EruoFood\Ai`)

The **AI Engine & Intelligent Recipe Generation** bounded context. It gives the
platform ten AI features on top of a single, provider-agnostic service layer,
built with the same Clean Architecture / DDD / Repository-Pattern / Service-Layer
/ DI conventions as the Identity and Catalog modules.

## What it owns

- **AI Service Layer** — one gateway that every feature calls, running the full
  cross-cutting pipeline (rate-limit → cache → provider with retry + fallback →
  cost + usage logging → domain event).
- **AI Provider Abstraction** — a single `AiProvider` port with adapters for
  **Anthropic (Claude)**, **OpenAI**, **Google Gemini**, a **local/self-hosted
  LLM** (OpenAI-compatible), and a deterministic **Mock** provider used in tests.
- **Prompt Management System** — database-backed, **versioned** prompt templates
  with `{{ variable }}` interpolation, one active version per feature, an admin
  API to publish/activate versions, and a **prompt testing framework**.
- **Supporting subsystems** — AI Context Builder, AI Response Parser, AI Usage
  Logging, AI Rate Limiting, AI Cost Tracking, response caching, retry + fallback.
- **Chat history** — a `Conversation` aggregate persisting the Smart Cooking
  Assistant's threads.

### The ten features

| Feature (`AiFeature`)       | Endpoint                     | Shape      |
|-----------------------------|------------------------------|------------|
| Recipe generation           | `POST /ai/recipes/generate`  | structured |
| Recipe improvement          | `POST /ai/recipes/improve`   | structured |
| Leftover recipe generator   | `POST /ai/recipes/leftovers` | structured |
| Recipe summarization        | `POST /ai/recipes/summarize` | text       |
| Recipe translation          | `POST /ai/recipes/translate` | text       |
| Food description generation | `POST /ai/foods/describe`    | text       |
| Smart cooking assistant     | `POST /ai/assistant/chat`    | chat       |
| Cooking tips generation     | `POST /ai/assistant/tips`    | text       |
| Ingredient substitution     | `POST /ai/assistant/substitute` | structured |
| Meal suggestions            | `POST /ai/assistant/meals`   | structured |

## Folder structure

```
modules/Ai/src/
├── Domain/                    # Pure PHP — no framework
│   ├── Enum/                  # AiFeature, AiProviderName, MessageRole
│   ├── ValueObject/           # AiMessage, TokenUsage, PromptVariables
│   ├── Prompt/                # PromptTemplate aggregate, RenderedPrompt, repo port
│   ├── Conversation/          # Conversation aggregate, ConversationMessage, repo port
│   ├── Usage/                 # AiUsageLog, UsageSummary, repo port
│   ├── Event/                 # AiRequestCompleted
│   └── Exception/             # PromptNotFound, ConversationNotFound, RateLimitExceeded,
│                              #   ProviderUnavailable, AiGenerationFailed
├── Application/               # Use cases + ports (framework-free)
│   ├── Port/                  # AiProvider, ProviderRegistry, AiResponseCache,
│   │                          #   AiRateLimiter, CostCalculator
│   ├── DTO/                   # AiCompletionRequest/Result, GeneratedContent, ChatTurn,
│   │                          #   GatewaySettings, GenerationDefaults
│   ├── Input/                 # One typed input per feature (fromArray)
│   ├── Service/               # AiGateway, FeatureRunner, PromptRegistry,
│   │                          #   AiContextBuilder, AiResponseParser, ConversationService,
│   │                          #   AiUsageService, PromptAdminService, AiPresenter
│   ├── Feature/               # RecipeGenerator, CookingAssistant, MealPlanner, ContentWriter
│   └── Testing/               # PromptTester + PromptTestCase/Report (prompt test framework)
├── Infrastructure/            # Adapters
│   ├── Provider/              # Anthropic/OpenAi/Gemini/Local/Mock providers,
│   │                          #   ContainerProviderRegistry, AiServiceProvider
│   ├── Cache/                 # Laravel + Null response caches
│   ├── RateLimit/             # Laravel + Null rate limiters
│   ├── Cost/                  # TableCostCalculator
│   ├── Persistence/           # Eloquent models, repositories, migrations
│   └── Seeder/                # DefaultPromptSeeder (v1 prompts for all features)
└── Interface/                 # HTTP delivery
    ├── Http/Controller/       # AiRecipe, AiAssistant, Conversation, AiUsage + Admin/Prompt
    ├── Http/Request/          # FormRequests (validation)
    ├── Http/Concerns/         # ResolvesAuthUser, RespondsWithData
    └── Http/routes.php
```

## The AI Service Layer (`AiGateway`)

Every feature funnels through `AiGateway::generate()`, which runs one pipeline so
behaviour is consistent and features stay a few lines each:

1. **Rate limit** — per-user quota (`AiRateLimiter`); throws `RateLimitExceeded`.
2. **Cache** — cacheable features (everything except chat) are keyed on
   `feature + rendered-prompt + model`; a hit is returned immediately and still
   logged (as `cached`).
3. **Provider call with resilience** — walk the provider **resolution chain**
   (default then fallbacks). Each provider is retried with exponential backoff
   before moving to the next (fallback). The first success wins.
4. **Cost + usage logging** — attribute tokens and a USD cost to the user/feature
   on the ledger (`AiUsageLog`), for both live and cached calls, successes and
   failures.
5. **Domain event** — publish `AiRequestCompleted` for downstream listeners.

## AI Provider Abstraction

`AiProvider` is the single seam that makes the engine multi-provider:

```php
interface AiProvider {
    public function name(): AiProviderName;
    public function isConfigured(): bool;
    public function complete(AiCompletionRequest $request): AiCompletionResult;
}
```

Adapters translate the neutral `AiCompletionRequest` into each vendor's wire
format (Anthropic keeps `system` top-level; OpenAI/local send it as the first
`system` message; Gemini uses `system_instruction` + `contents` with the
`model` role). HTTP is done with Laravel's HTTP client — **no extra Composer
dependency**. The `ContainerProviderRegistry` filters the chain to providers that
are actually configured, so a provider missing its API key is skipped, not fatal.

The **MockProvider** is deterministic and offline: it returns valid JSON when the
prompt asks for JSON and prose otherwise. This is what lets the whole engine —
gateway, caching, feature services, controllers — run in tests with zero
credentials and zero cost (`AI_PROVIDER=mock` in `phpunit.xml`).

## Prompt Management System

`PromptTemplate` is a versioned aggregate belonging to one `AiFeature`. Many
versions may exist; exactly one is **active** and served at runtime
(`PromptRegistry::activeFor()`). Prompts carry a `system` and `user` body with
`{{ variable }}` placeholders rendered from `PromptVariables`. `DefaultPromptSeeder`
seeds v1 for every feature (idempotently). Admins publish new versions and roll
the active one forward/back via `/admin/ai/prompts` — the prompt is treated as
source code, not a magic string.

The **Prompt Testing Framework** (`PromptTester`) runs declarative
`PromptTestCase`s (expected substrings / JSON keys) against the active prompt
through the real gateway (mock provider in tests) — a fast, offline regression
check for prompt edits.

## Configuration (`config/ai.php`)

Provider credentials, the default provider + ordered fallback chain, per-provider
default models, cache TTL/toggle, retry policy, per-user rate limits and the
**pricing table** (USD per 1M tokens, keyed by `provider/model`) are all read
from the environment. In `testing` the default provider is forced to `mock`.

Key env vars: `AI_PROVIDER`, `AI_FALLBACKS`, `ANTHROPIC_API_KEY`,
`OPENAI_API_KEY`, `GEMINI_API_KEY`, `LOCAL_LLM_BASE_URL`, `AI_CACHE_ENABLED`,
`AI_CACHE_TTL`, `AI_RETRY_ATTEMPTS`, `AI_RATE_LIMIT_MAX`, `AI_RATE_LIMIT_WINDOW`.

## Persistence

Four tables (prefixed `ai_`): `ai_prompt_templates`, `ai_conversations`,
`ai_conversation_messages`, `ai_usage_logs`. Other contexts are referenced by ID
(soft references, no cross-context joins) — e.g. `user_id` → Identity.

Seed the default prompts:

```
php artisan db:seed --class="EruoFood\Ai\Infrastructure\Seeder\DefaultPromptSeeder"
```

## Error → HTTP mapping

Domain exceptions map to stable codes in `bootstrap/app.php`:
`AI_PROMPT_NOT_FOUND`/`AI_CONVERSATION_NOT_FOUND` → 404, `AI_RATE_LIMIT_EXCEEDED`
→ 429, `AI_GENERATION_FAILED` → 502, `AI_PROVIDER_UNAVAILABLE` → 503.

## Testing

- **Unit** (`tests/Unit`) — prompt rendering, response parsing, mock provider,
  cost calculation, the conversation aggregate, and the **gateway** (cache hit,
  retry, fallback, rate-limit, usage logging) using in-memory fakes in
  `tests/Support`.
- **Feature** (`tests/Feature`) — recipe generation, the assistant chat + history
  flow, and prompt admin, end to end against the mock provider.

See [docs/api/ai-endpoints.md](../../../../docs/api/ai-endpoints.md) for the HTTP
reference and [ADR-0004](../../../../docs/adr/0004-ai-engine-provider-abstraction.md)
for the design rationale.
