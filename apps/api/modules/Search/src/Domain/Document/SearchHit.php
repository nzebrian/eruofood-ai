<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Document;

/**
 * One ranked result: the matched document plus the score components that ranked
 * it, an optional distance (for geo queries) and a highlighted snippet.
 */
final readonly class SearchHit
{
    public function __construct(
        public SearchDocument $document,
        public float $score,
        public float $lexicalScore,
        public float $semanticScore,
        public ?float $distanceKm = null,
        public ?string $highlight = null,
    ) {
    }

    public function withScore(float $score): self
    {
        return new self($this->document, $score, $this->lexicalScore, $this->semanticScore, $this->distanceKm, $this->highlight);
    }
}
