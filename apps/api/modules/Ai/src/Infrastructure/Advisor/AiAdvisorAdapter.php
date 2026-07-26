<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Advisor;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\GenerationDefaults;
use EruoFood\Ai\Application\Service\AiGateway;
use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdviceResult;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\AiMessage;

/**
 * Implements the public {@see AiAdvisor} contract on top of the AI Engine's
 * {@see AiGateway}. External callers get the full pipeline (provider selection,
 * fallback, caching, rate-limiting, cost + usage logging) while their requests
 * are attributed to the neutral {@see AiFeature::ExternalAdvice} bucket. Unlike
 * the built-in features, the caller supplies the prompt directly, so this path
 * bypasses the internal prompt registry.
 */
final readonly class AiAdvisorAdapter implements AiAdvisor
{
    public function __construct(
        private AiGateway $gateway,
        private GenerationDefaults $defaults,
    ) {
    }

    public function advise(AiAdviceRequest $request): AiAdviceResult
    {
        $completion = new AiCompletionRequest(
            system: $request->system,
            messages: [AiMessage::user($request->prompt)],
            maxTokens: $this->defaults->maxTokens,
            temperature: $this->defaults->temperature,
        );

        $result = $this->gateway->generate(
            AiFeature::ExternalAdvice,
            $completion,
            $request->userId,
            $request->cacheable,
        );

        return new AiAdviceResult(
            text: $result->text,
            provider: $result->provider->value,
            model: $result->model,
            cached: $result->cached,
        );
    }
}
