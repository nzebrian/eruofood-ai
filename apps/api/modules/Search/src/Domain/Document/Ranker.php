<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Document;

use EruoFood\Search\Domain\Enum\SortOption;

/**
 * The ranking algorithm, kept framework-free so it can be unit-tested in
 * isolation. Relevance blends a lexical score (full-text / term-overlap, in
 * [0,1]) with a semantic score (vector cosine mapped to [0,1]); a small
 * popularity boost breaks ties so equally-relevant items favour proven ones.
 * The non-relevance sorts order purely by the corresponding facet.
 */
final readonly class Ranker
{
    public function __construct(
        private float $lexicalWeight = 0.6,
    ) {
    }

    /** Blend the two relevance signals into a single score, with a popularity tie-breaker. */
    public function blend(float $lexical, float $semantic, int $popularity): float
    {
        $base = $this->lexicalWeight * $lexical + (1.0 - $this->lexicalWeight) * $semantic;
        // Popularity contributes a small logarithmic nudge (never dominates relevance).
        $boost = 0.05 * (log10(max(1, $popularity) + 1) / 3.0);

        return $base + $boost;
    }

    /**
     * Order hits for the requested sort. Relevance uses the blended score;
     * everything else orders by the facet, pushing missing values to the end.
     *
     * @param list<SearchHit> $hits
     * @return list<SearchHit>
     */
    public function sort(array $hits, SortOption $sort): array
    {
        $comparator = match ($sort) {
            SortOption::Relevance => static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score,
            SortOption::Popularity => static fn (SearchHit $a, SearchHit $b): int => $b->document->facets()->popularity <=> $a->document->facets()->popularity,
            SortOption::Rating => static fn (SearchHit $a, SearchHit $b): int => $b->document->facets()->rating <=> $a->document->facets()->rating,
            SortOption::Newest => static fn (SearchHit $a, SearchHit $b): int => $b->document->updatedAt() <=> $a->document->updatedAt(),
            SortOption::Price => static fn (SearchHit $a, SearchHit $b): int => self::nullsLast($a->document->facets()->priceMinor) <=> self::nullsLast($b->document->facets()->priceMinor),
            SortOption::PreparationTime => static fn (SearchHit $a, SearchHit $b): int => self::nullsLast($a->document->facets()->prepTimeMinutes) <=> self::nullsLast($b->document->facets()->prepTimeMinutes),
            SortOption::Distance => static fn (SearchHit $a, SearchHit $b): int => self::nullsLastFloat($a->distanceKm) <=> self::nullsLastFloat($b->distanceKm),
        };

        usort($hits, $comparator);

        return array_values($hits);
    }

    private static function nullsLast(?int $value): int
    {
        return $value ?? PHP_INT_MAX;
    }

    private static function nullsLastFloat(?float $value): float
    {
        return $value ?? PHP_FLOAT_MAX;
    }
}
