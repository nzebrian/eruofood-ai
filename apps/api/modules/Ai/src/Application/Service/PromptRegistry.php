<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Exception\PromptNotFound;
use EruoFood\Ai\Domain\Prompt\PromptRepository;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;

/**
 * The Prompt Management System's read-side: resolves the active, versioned
 * prompt template for a feature and memoises it for the duration of a request
 * so a feature that renders several prompts hits the database once.
 */
final class PromptRegistry
{
    /** @var array<string, PromptTemplate> */
    private array $cache = [];

    public function __construct(private readonly PromptRepository $prompts)
    {
    }

    /** @throws PromptNotFound */
    public function activeFor(AiFeature $feature): PromptTemplate
    {
        return $this->cache[$feature->value] ??= (
            $this->prompts->activeForFeature($feature) ?? throw PromptNotFound::forFeature($feature)
        );
    }
}
