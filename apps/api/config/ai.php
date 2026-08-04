<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| AI Engine module configuration
|------------------------------------------------------------------------------
| Central configuration for the AI bounded context: provider credentials, the
| default provider + ordered fallback chain, per-provider default models, the
| response cache, rate limiting, retry policy and the pricing table used for
| cost tracking. Everything secret is read from the environment; nothing is
| hard-coded so the same build runs unchanged across dev/stage/prod.
|
| The "mock" provider is deterministic and needs no credentials, which is what
| lets the whole engine run in tests and local development with no external
| calls. When APP_ENV=testing we default to it (see `default`).
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Default provider + fallback chain
    |--------------------------------------------------------------------------
    | AiGateway tries `default` first; on a provider failure it walks the
    | `fallbacks` list in order. In testing we force the mock provider so the
    | suite never reaches the network.
    */
    'default' => env('AI_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'anthropic'),

    /** @var list<string> Ordered providers tried after the default fails. */
    'fallbacks' => array_values(array_filter(
        explode(',', (string) env('AI_FALLBACKS', 'openai,gemini')),
        static fn (string $p): bool => $p !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Each entry configures one AiProvider adapter. `model` is the provider's
    | default model when a prompt template does not pin one. Local LLMs use an
    | OpenAI-compatible endpoint (Ollama, LM Studio, vLLM, …).
    */
    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        ],
        'local' => [
            'api_key' => env('LOCAL_LLM_API_KEY', 'not-needed'),
            'base_url' => env('LOCAL_LLM_BASE_URL', 'http://localhost:11434/v1'),
            'model' => env('LOCAL_LLM_MODEL', 'llama3.1'),
        ],
        'mock' => [
            'model' => 'mock-1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),
        'temperature' => (float) env('AI_TEMPERATURE', 0.7),
        'timeout' => (int) env('AI_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response cache
    |--------------------------------------------------------------------------
    | Identical (feature + rendered-prompt + model) requests are served from
    | cache. Chat is never cached (each turn is unique). TTL is in seconds.
    */
    'cache' => [
        'enabled' => (bool) env('AI_CACHE_ENABLED', true),
        'store' => env('AI_CACHE_STORE', null), // null => default cache store
        'ttl' => (int) env('AI_CACHE_TTL', 86400),
        'prefix' => 'ai:resp:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry policy (per provider, before falling back to the next provider)
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'attempts' => (int) env('AI_RETRY_ATTEMPTS', 2),
        'base_delay_ms' => (int) env('AI_RETRY_BASE_DELAY_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (per user, per rolling window)
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        'enabled' => (bool) env('AI_RATE_LIMIT_ENABLED', true),
        'max_requests' => (int) env('AI_RATE_LIMIT_MAX', 60),
        'window_seconds' => (int) env('AI_RATE_LIMIT_WINDOW', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost tracking — USD price per 1M tokens, keyed by "provider/model".
    |--------------------------------------------------------------------------
    | Used by the TableCostCalculator to attribute a dollar cost to each call
    | for the AI usage/billing ledger. A missing key falls back to `default`.
    */
    'pricing' => [
        'anthropic/claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'anthropic/claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gemini/gemini-1.5-pro' => ['input' => 1.25, 'output' => 5.00],
        'local/llama3.1' => ['input' => 0.0, 'output' => 0.0],
        'mock/mock-1' => ['input' => 0.0, 'output' => 0.0],
        'default' => ['input' => 3.00, 'output' => 15.00],
    ],
];
