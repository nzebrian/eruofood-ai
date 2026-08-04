<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\Public;

use EruoFood\PublicApi\Application\Service\PublicSearchService;
use EruoFood\PublicApi\Interface\Http\Concerns\RespondsWithEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public search (scope: search:read). Delegates to the Search context's own
 * pipeline via the read port. The public surface is never administrative — the
 * admin-only user scope is refused downstream — and every query runs over public
 * content only.
 */
final class SearchController
{
    use RespondsWithEnvelope;

    public function __construct(private readonly PublicSearchService $search)
    {
    }

    public function query(Request $request): JsonResponse
    {
        $query = $this->resourceQuery($request);
        $filters = $query->filters;

        $type = $request->query('type');
        $filters['sort'] = is_string($request->query('sort')) ? (string) $request->query('sort') : ($filters['sort'] ?? '');
        $filters['locale'] = is_string($request->query('locale')) ? (string) $request->query('locale') : 'en';

        $results = $this->search->query(
            $query->search ?? '',
            is_string($type) && $type !== '' ? $type : null,
            $filters,
            $query->page,
            $query->perPage,
        );

        return $this->item($results);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $prefix = $request->query('q');

        return $this->item([
            'suggestions' => $this->search->suggestions(
                is_string($prefix) ? $prefix : '',
                is_string($type) && $type !== '' ? $type : null,
            ),
        ]);
    }

    public function filters(): JsonResponse
    {
        return $this->item(['filters' => $this->search->filters()]);
    }
}
