<?php

declare(strict_types=1);

namespace EruoFood\Ai\Contracts;

/**
 * A request another bounded context makes to the AI Engine through the public
 * {@see AiAdvisor} contract. The caller supplies the full system + user prompt;
 * the AI module runs it through its gateway (provider selection, caching,
 * rate-limiting, cost + usage logging) without knowing the caller's domain.
 */
final readonly class AiAdviceRequest
{
    public function __construct(
        public string $system,
        public string $prompt,
        public ?string $userId = null,
        public bool $cacheable = true,
    ) {
    }
}
