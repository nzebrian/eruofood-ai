<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Understanding;

use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Search\Application\Port\QueryUnderstanding;
use Throwable;

/**
 * AI-powered query understanding via the AI engine's public contract. It asks
 * the model for a few alternative phrasings / intent terms and returns them for
 * OR-expansion. It depends only on {@see AiAdvisor} (the AI module's published
 * contract) — never on the AI internals — and fails soft (returns nothing) so a
 * provider hiccup never breaks search.
 */
final readonly class AiQueryUnderstanding implements QueryUnderstanding
{
    public function __construct(private AiAdvisor $advisor)
    {
    }

    public function expand(string $rawQuery, string $locale): array
    {
        $rawQuery = trim($rawQuery);
        if ($rawQuery === '') {
            return [];
        }

        try {
            $result = $this->advisor->advise(new AiAdviceRequest(
                system: 'You expand food/recipe/shopping search queries. Reply with 1-4 short alternative search terms, comma-separated, no prose.',
                prompt: sprintf('Query (locale %s): "%s"', $locale, $rawQuery),
                userId: null,
                cacheable: true,
            ));
        } catch (Throwable) {
            return [];
        }

        $terms = array_map('trim', explode(',', $result->text));

        return array_values(array_filter(
            $terms,
            static fn (string $t): bool => $t !== '' && mb_strlen($t) <= 40,
        ));
    }
}
