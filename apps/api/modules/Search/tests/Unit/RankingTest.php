<?php

declare(strict_types=1);

use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Document\Ranker;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Document\SearchHit;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\Embedding;

function doc(string $id, DocumentFacets $facets): SearchDocument
{
    return SearchDocument::create(
        SearchType::Food,
        $id,
        'Title '.$id,
        'desc',
        [],
        null,
        null,
        'en',
        $facets,
        null,
        new DateTimeImmutable('2026-07-'.substr($id, -2)),
    );
}

it('blends lexical and semantic with a popularity tie-breaker', function (): void {
    $ranker = new Ranker(0.6);
    $strong = $ranker->blend(1.0, 1.0, 100);
    $weak = $ranker->blend(0.2, 0.1, 0);
    expect($strong)->toBeGreaterThan($weak);

    // Equal relevance, higher popularity wins.
    $popular = $ranker->blend(0.5, 0.5, 1000);
    $niche = $ranker->blend(0.5, 0.5, 1);
    expect($popular)->toBeGreaterThan($niche);
});

it('sorts by rating, price and preparation time', function (): void {
    $ranker = new Ranker();
    $a = new SearchHit(doc('10', new DocumentFacets(rating: 3.0, priceMinor: 500, prepTimeMinutes: 40)), 0.5, 0.5, 0.0);
    $b = new SearchHit(doc('20', new DocumentFacets(rating: 5.0, priceMinor: 200, prepTimeMinutes: 10)), 0.5, 0.5, 0.0);

    expect($ranker->sort([$a, $b], SortOption::Rating)[0]->document->id())->toBe('food:20');
    expect($ranker->sort([$a, $b], SortOption::Price)[0]->document->id())->toBe('food:20');
    expect($ranker->sort([$a, $b], SortOption::PreparationTime)[0]->document->id())->toBe('food:20');
});

it('pushes missing sort values to the end', function (): void {
    $ranker = new Ranker();
    $priced = new SearchHit(doc('11', new DocumentFacets(priceMinor: 999)), 0.1, 0.0, 0.0);
    $unpriced = new SearchHit(doc('12', new DocumentFacets(priceMinor: null)), 0.9, 0.0, 0.0);

    $sorted = $ranker->sort([$unpriced, $priced], SortOption::Price);
    expect($sorted[0]->document->id())->toBe('food:11');
});

it('maps cosine to [0,1] via the embedding', function (): void {
    $a = new Embedding([1.0, 0.0, 0.0]);
    $b = new Embedding([1.0, 0.0, 0.0]);
    $c = new Embedding([-1.0, 0.0, 0.0]);
    expect($a->cosineTo($b))->toBe(1.0)
        ->and($a->cosineTo($c))->toBe(-1.0);
});
