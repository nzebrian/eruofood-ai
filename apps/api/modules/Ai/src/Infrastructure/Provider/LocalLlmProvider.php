<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Domain\Enum\AiProviderName;

/**
 * Self-hosted / local LLM adapter (Ollama, LM Studio, vLLM …) exposing an
 * OpenAI-compatible endpoint. Treated as always-configured because local
 * runtimes typically need no API key.
 */
final readonly class LocalLlmProvider extends OpenAiCompatibleProvider
{
    public function name(): AiProviderName
    {
        return AiProviderName::Local;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['base_url']);
    }
}
