<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Ai;

use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Commerce\Application\Port\CommerceAdvisor;

/**
 * Shopping intelligence via the AI module's published {@see AiAdvisor} contract.
 * This is the only place commerce touches the AI context, and it does so through
 * the Contract — never AI internals — the same clean cross-context pattern the
 * Marketplace and Nutrition modules use.
 */
final readonly class AiCommerceAdvisor implements CommerceAdvisor
{
    private const BLURB_SYSTEM = 'You are a shopping copywriter for a Nigerian online marketplace. '
        .'Write ONE friendly, concise sentence (max 25 words) to caption a set of product suggestions. '
        .'No emojis, no quotes, no preamble — just the sentence.';

    private const LIST_SYSTEM = 'You are a grocery shopping assistant for a Nigerian marketplace. '
        .'Given a request, reply with a plain shopping list: one item per line, no numbering, no extra prose. '
        .'Keep each line to an item name and optional quantity.';

    private const ASSIST_SYSTEM = 'You are a helpful shopping assistant for a Nigerian online marketplace. '
        .'Answer the shopper concisely and practically in 2-4 sentences.';

    public function __construct(private AiAdvisor $ai)
    {
    }

    public function recommendationBlurb(string $context, array $productNames, ?string $userId): string
    {
        if ($productNames === []) {
            return '';
        }
        $prompt = sprintf(
            'Context: %s. Products: %s.',
            $context,
            implode(', ', array_slice($productNames, 0, 8)),
        );

        return trim($this->ai->advise(new AiAdviceRequest(self::BLURB_SYSTEM, $prompt, $userId, cacheable: true))->text);
    }

    public function buildShoppingList(string $request, ?string $userId): array
    {
        $text = $this->ai->advise(new AiAdviceRequest(self::LIST_SYSTEM, $request, $userId, cacheable: true))->text;

        $lines = [];
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            $clean = trim(preg_replace('/^[\s\-\*\d\.\)]+/', '', $line) ?? '');
            if ($clean !== '') {
                $lines[] = $clean;
            }
        }

        return array_values(array_slice($lines, 0, 40));
    }

    public function assist(string $question, ?string $userId): string
    {
        return trim($this->ai->advise(new AiAdviceRequest(self::ASSIST_SYSTEM, $question, $userId, cacheable: true))->text);
    }
}
