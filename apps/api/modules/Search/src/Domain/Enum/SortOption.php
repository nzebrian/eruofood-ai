<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Enum;

/**
 * How a result set is ordered. `Relevance` (the default) uses the blended
 * lexical + semantic score; the rest order by an indexed attribute. `Distance`
 * requires a geo point on the query.
 */
enum SortOption: string
{
    case Relevance = 'relevance';
    case Popularity = 'popularity';
    case Rating = 'rating';
    case Newest = 'newest';
    case Price = 'price';
    case PreparationTime = 'prep_time';
    case Distance = 'distance';

    public function requiresGeo(): bool
    {
        return $this === self::Distance;
    }
}
