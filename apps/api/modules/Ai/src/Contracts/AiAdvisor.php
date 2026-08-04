<?php

declare(strict_types=1);

namespace EruoFood\Ai\Contracts;

/**
 * The AI module's PUBLIC contract for other bounded contexts.
 *
 * Modules like Nutrition depend on this interface (never on the AI module's
 * internals) to obtain AI-generated text, honouring the Modular Monolith rule
 * that contexts integrate only through published Contracts.
 */
interface AiAdvisor
{
    /**
     * Generate advice text from a caller-supplied prompt.
     *
     * @throws \EruoFood\Ai\Domain\Exception\RateLimitExceeded
     * @throws \EruoFood\Ai\Domain\Exception\AiGenerationFailed
     */
    public function advise(AiAdviceRequest $request): AiAdviceResult;
}
