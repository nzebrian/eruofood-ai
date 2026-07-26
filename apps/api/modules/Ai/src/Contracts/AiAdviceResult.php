<?php

declare(strict_types=1);

namespace EruoFood\Ai\Contracts;

/** The result of an {@see AiAdvisor} call — the generated text plus provenance. */
final readonly class AiAdviceResult
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public bool $cached,
    ) {
    }
}
