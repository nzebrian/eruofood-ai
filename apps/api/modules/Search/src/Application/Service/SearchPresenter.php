<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Document\SearchHit;
use EruoFood\Search\Domain\Document\SearchResults;
use EruoFood\Search\Domain\SavedSearch\SavedSearch;

/** Maps Search domain objects to API-shaped arrays. */
final readonly class SearchPresenter
{
    /** @return array<string, mixed> */
    public function results(SearchResults $results, ?string $queryId = null): array
    {
        return [
            'query_id' => $queryId,
            'total' => $results->total,
            'page' => $results->page,
            'per_page' => $results->perPage,
            'facets' => $results->facets,
            'hits' => array_map(fn (SearchHit $h): array => $this->hit($h), $results->hits),
        ];
    }

    /** @return array<string, mixed> */
    public function hit(SearchHit $hit): array
    {
        return [
            'document' => $this->document($hit->document),
            'score' => round($hit->score, 5),
            'lexical_score' => round($hit->lexicalScore, 5),
            'semantic_score' => round($hit->semanticScore, 5),
            'distance_km' => $hit->distanceKm !== null ? round($hit->distanceKm, 2) : null,
            'highlight' => $hit->highlight,
        ];
    }

    /** @return array<string, mixed> */
    public function document(SearchDocument $d): array
    {
        $facets = $d->facets();

        return [
            'id' => $d->id(),
            'type' => $d->type()->value,
            'source_id' => $d->sourceId(),
            'title' => $d->title(),
            'description' => $d->description(),
            'url' => $d->url(),
            'image' => $d->image(),
            'locale' => $d->locale(),
            'region' => $facets->region,
            'cuisine' => $facets->cuisine,
            'category' => $facets->category,
            'rating' => $facets->rating,
            'popularity' => $facets->popularity,
            'price_minor' => $facets->priceMinor,
            'prep_time_minutes' => $facets->prepTimeMinutes,
            'difficulty' => $facets->difficulty,
            'tags' => $facets->dietary,
        ];
    }

    /** @return array<string, mixed> */
    public function savedSearch(SavedSearch $s): array
    {
        return [
            'id' => $s->id(),
            'name' => $s->name(),
            'term' => $s->term(),
            'type' => $s->type()->value,
            'sort' => $s->sort()->value,
            'filters' => $s->filters()->toArray(),
            'created_at' => $s->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function popularTerm(PopularTerm $t): array
    {
        return ['term' => $t->term, 'count' => $t->count];
    }
}
