<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\DTO;

use EruoFood\Search\Domain\Document\SearchResults;

/**
 * The outcome of running a query through the pipeline: the ranked results plus
 * the analytics query id, which the client echoes back on a result click so the
 * click can be attributed (click-through rate).
 */
final readonly class ExecutedSearch
{
    public function __construct(
        public SearchResults $results,
        public string $queryId,
    ) {
    }
}
