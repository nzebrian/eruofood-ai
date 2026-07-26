<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Prompt;

/**
 * The concrete system + user text produced by rendering a {@see PromptTemplate}
 * with a set of variables. Feeds directly into the provider request and the
 * response-cache key.
 */
final readonly class RenderedPrompt
{
    public function __construct(
        public string $system,
        public string $user,
    ) {
    }

    /** Stable fingerprint of the rendered content, used for response caching. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->system."\n---\n".$this->user);
    }
}
