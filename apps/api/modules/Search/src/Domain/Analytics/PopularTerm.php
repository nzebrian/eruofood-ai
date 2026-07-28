<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Analytics;

/** A search term with how many times it was queried over a window. */
final readonly class PopularTerm
{
    public function __construct(
        public string $term,
        public int $count,
    ) {
    }
}
