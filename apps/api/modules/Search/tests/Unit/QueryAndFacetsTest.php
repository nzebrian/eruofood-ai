<?php

declare(strict_types=1);

use EruoFood\Search\Application\Port\QueryUnderstanding;
use EruoFood\Search\Application\Service\QueryBuilder;
use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\Exception\SearchInvalidQuery;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use EruoFood\Search\Infrastructure\Embedding\HashingEmbeddingGenerator;

function builder(bool $ai = false, ?QueryUnderstanding $understanding = null): QueryBuilder
{
    $understanding ??= new class implements QueryUnderstanding {
        public function expand(string $rawQuery, string $locale): array
        {
            return ['ai-term'];
        }
    };

    return new QueryBuilder($understanding, [['jollof', 'party rice']], 20, 100, $ai);
}

it('normalises the term and expands synonyms', function (): void {
    $q = builder()->build('Jollof', SearchType::Global, new SearchFilters(), SortOption::Relevance, 1, 0, 'en', null);
    expect($q->term)->toBe('jollof')
        ->and($q->expandedTerms)->toContain('party rice')
        ->and($q->perPage)->toBe(20);
});

it('clamps pagination and floors the page', function (): void {
    $q = builder()->build('rice', SearchType::Food, new SearchFilters(), SortOption::Relevance, 0, 500, 'en', null);
    expect($q->page)->toBe(1)->and($q->perPage)->toBe(100);
});

it('only applies AI expansion when enabled', function (): void {
    $off = builder(false)->build('rice', SearchType::Food, new SearchFilters(), SortOption::Relevance, 1, 10, 'en', null);
    $on = builder(true)->build('rice', SearchType::Food, new SearchFilters(), SortOption::Relevance, 1, 10, 'en', null);
    expect($off->expandedTerms)->not->toContain('ai-term')
        ->and($on->expandedTerms)->toContain('ai-term');
});

it('rejects distance sort without a location', function (): void {
    expect(fn () => builder()->build('rice', SearchType::Restaurant, new SearchFilters(), SortOption::Distance, 1, 10, 'en', null))
        ->toThrow(SearchInvalidQuery::class);
});

it('matches documents against every filter dimension', function (): void {
    $facets = new DocumentFacets(
        region: 'South West', states: ['Lagos'], cuisine: 'Yoruba', category: 'rice',
        ingredients: ['rice', 'pepper'], dietary: ['halal'], allergens: ['gluten'],
        difficulty: 'medium', rating: 4.5, priceMinor: 250000, prepTimeMinutes: 45, calories: 600,
    );

    expect($facets->matches(new SearchFilters(region: 'south west', state: 'lagos', ingredients: ['rice'])))->toBeTrue()
        ->and($facets->matches(new SearchFilters(excludeAllergens: ['gluten'])))->toBeFalse()
        ->and($facets->matches(new SearchFilters(maxCalories: 500)))->toBeFalse()
        ->and($facets->matches(new SearchFilters(minRating: 4.0)))->toBeTrue();
});

it('produces deterministic embeddings where similar text is closer', function (): void {
    $embedder = new HashingEmbeddingGenerator(64);
    $a = $embedder->embed('jollof rice chicken pepper');
    $related = $embedder->embed('spicy jollof rice chicken');
    $unrelated = $embedder->embed('banana bread dessert');

    expect($embedder->embed('jollof rice')->toArray())->toBe($embedder->embed('jollof rice')->toArray())
        ->and($a->cosineTo($related))->toBeGreaterThan($a->cosineTo($unrelated));
});
