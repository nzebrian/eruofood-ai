<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Ai;

use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Marketplace\Application\Port\MenuDescriber;

/**
 * Generates appetising menu-item descriptions via the AI module's published
 * {@see AiAdvisor} contract. This is the only place the marketplace touches the
 * AI context, and it does so through a Contract — never AI internals.
 */
final readonly class AiMenuDescriber implements MenuDescriber
{
    private const SYSTEM = 'You are a food copywriter for a Nigerian food-delivery marketplace. '
        .'Write one vivid, appetising sentence (max 30 words) that would make a hungry customer order. '
        .'No emojis, no quotes, no preamble — just the sentence.';

    public function __construct(private AiAdvisor $ai)
    {
    }

    public function describe(string $vendorName, string $itemName, string $category, array $tags, ?string $userId): string
    {
        $prompt = sprintf(
            'Write a description for the menu item "%s" sold by "%s" (a %s vendor). Tags: %s.',
            $itemName,
            $vendorName,
            $category,
            $tags === [] ? 'none' : implode(', ', $tags),
        );

        return trim($this->ai->advise(new AiAdviceRequest(self::SYSTEM, $prompt, $userId, cacheable: true))->text);
    }
}
