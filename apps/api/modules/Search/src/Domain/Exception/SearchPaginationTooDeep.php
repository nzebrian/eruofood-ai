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
 *
 * ## The boundary rule
 *
 * The requested page must fit ENTIRELY inside the window:
 *
 *     offset + per_page <= search.max_result_window
 *
 * So with a 1000-row window and 20 per page, offset 980 is the last accepted
 * page (980 + 20 = 1000) and offset 981 is refused. A page that merely *starts*
 * inside the window is not enough: clamping it would return a short page that
 * looks like the end of the results and is not, which is the original defect
 * displaced by one page rather than fixed.
 */
final class SearchPaginationTooDeep extends DomainException
{
    public function errorCode(): string
    {
        return 'SEARCH_PAGINATION_TOO_DEEP';
    }

    public static function beyond(int $window, int $offset, int $perPage): self
    {
        return new self(sprintf(
            'This page is beyond the %d-result ranking window for relevance/distance sorting '
            .'(requested offset %d with %d per page; the whole page must fit inside the '
            .'window, so the last available offset at this page size is %d). Narrow the query '
            .'with filters, or use a sort that PostgreSQL can order directly (popularity, '
            .'rating, newest, price, prep_time), which paginates exactly at any depth.',
            $window,
            $offset,
            $perPage,
            max(0, $window - $perPage),
        ));
    }
}
