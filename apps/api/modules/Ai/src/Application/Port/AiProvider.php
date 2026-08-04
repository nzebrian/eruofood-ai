<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Port;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Domain\Enum\AiProviderName;

/**
 * The AI Provider Abstraction — the seam that makes the engine multi-provider.
 *
 * Every LLM backend (Anthropic, OpenAI, Gemini, a local model, or the
 * deterministic mock) implements this single port. The application layer talks
 * only to this interface, so swapping or adding a provider never touches the
 * gateway or feature services (Open/Closed + Dependency Inversion).
 */
interface AiProvider
{
    public function name(): AiProviderName;

    /** Whether the provider has the credentials/config it needs to be called. */
    public function isConfigured(): bool;

    /**
     * Execute a completion.
     *
     * @throws \EruoFood\Ai\Domain\Exception\AiGenerationFailed on any upstream failure
     */
    public function complete(AiCompletionRequest $request): AiCompletionResult;
}
