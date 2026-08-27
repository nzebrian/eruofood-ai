<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The requested page lies beyond the depth this sort can rank truthfully
 * (M38-SEARCH-001).
 *
 * Relevance and distance are blended in PHP, so they need a materialised
 * window; `search.max_result_window` bounds it. Asking past that bound used to
 * return an empty page while `total` still claimed more results existed — a
 * silent lie. It is now an explicit, actionable refusal: narrow the query, or
 * sort by a column PostgreSQL can order (popularity, rating, price, …), which
 * is paginated in SQL and exact at any depth.
 */
final class SearchPaginationTooDeep extends DomainException
{
    public function errorCode(): string
    {
        return 'SEARCH_PAGINATION_TOO_DEEP';
    }

    public static function beyond(int $window, int $offset): self
    {
        return new self(sprintf(
            'This page is beyond the %d-result ranking window for relevance/distance sorting '
            .'(requested offset %d). Narrow the query with filters, or use a sort that '
            .'PostgreSQL can order directly (popularity, rating, newest, price, prep_time), '
            .'which paginates exactly at any depth.',
            $window,
            $offset,
        ));
    }
}
