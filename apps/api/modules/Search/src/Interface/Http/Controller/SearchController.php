<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Controller;

use EruoFood\Search\Application\Service\AutocompleteService;
use EruoFood\Search\Application\Service\QueryBuilder;
use EruoFood\Search\Application\Service\SearchAnalyticsService;
use EruoFood\Search\Application\Service\SearchPresenter;
use EruoFood\Search\Application\Service\SearchService;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Interface\Http\Concerns\ParsesSearchParams;
use EruoFood\Search\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Search\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public search surface: global & typed search, autocomplete, suggestions,
 * trending, plus authenticated recent-searches, admin user search, and click
 * logging. Every query flows through the SearchService pipeline.
 */
final class SearchController
{
    use ParsesSearchParams;
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly SearchService $search,
        private readonly QueryBuilder $queryBuilder,
        private readonly AutocompleteService $autocomplete,
        private readonly SearchAnalyticsService $analytics,
        private readonly SearchPresenter $presenter,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        return $this->runSearch($request, $this->searchType($request));
    }

    /** Admin-only user search (the SearchService gates the User scope). */
    public function users(Request $request): JsonResponse
    {
        return $this->runSearch($request, SearchType::User);
    }

    private function runSearch(Request $request, SearchType $type): JsonResponse
    {
        $query = $this->queryBuilder->build(
            term: (string) $request->query('q', ''),
            type: $type,
            filters: $this->filters($request),
            sort: $this->sortOption($request),
            page: (int) $request->query('page', '1'),
            perPage: (int) $request->query('per_page', '0'),
            locale: (string) $request->query('locale', 'en'),
            geo: $this->geoPoint($request),
        );

        $executed = $this->search->search(
            $query,
            $this->actorIsAdmin($request),
            $this->optionalUserId($request),
        );

        return $this->data($this->presenter->results($executed->results, $executed->queryId));
    }

    public function autocomplete(Request $request): JsonResponse
    {
        $type = $this->searchType($request);

        return $this->data([
            'suggestions' => $this->autocomplete->autocomplete((string) $request->query('q', ''), $type),
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $type = $this->searchType($request);

        return $this->data([
            'suggestions' => $this->autocomplete->suggestions((string) $request->query('q', ''), $type),
        ]);
    }

    public function trending(): JsonResponse
    {
        return $this->data(['trending' => $this->autocomplete->trending()]);
    }

    public function recent(Request $request): JsonResponse
    {
        return $this->data(['recent' => $this->autocomplete->recent($this->requireUserId($request))]);
    }

    public function click(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query_id' => ['required', 'string'],
            'document_id' => ['required', 'string'],
            'position' => ['nullable', 'integer'],
            'from_recommendation' => ['nullable', 'boolean'],
        ]);
        $this->analytics->recordClick(
            $data['query_id'],
            $data['document_id'],
            (int) ($data['position'] ?? 0),
            (bool) ($data['from_recommendation'] ?? false),
        );

        return new JsonResponse(null, 204);
    }
}
