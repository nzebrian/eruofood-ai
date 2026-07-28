<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Controller;

use EruoFood\Search\Application\Service\QueryBuilder;
use EruoFood\Search\Application\Service\SavedSearchService;
use EruoFood\Search\Application\Service\SearchPresenter;
use EruoFood\Search\Application\Service\SearchService;
use EruoFood\Search\Domain\SavedSearch\SavedSearch;
use EruoFood\Search\Interface\Http\Concerns\ParsesSearchParams;
use EruoFood\Search\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Search\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A user's saved searches: list, save, delete and re-run. */
final class SavedSearchController
{
    use ParsesSearchParams;
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly SavedSearchService $savedSearches,
        private readonly QueryBuilder $queryBuilder,
        private readonly SearchService $search,
        private readonly SearchPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);

        return $this->data(['saved_searches' => array_map(
            fn (SavedSearch $s): array => $this->presenter->savedSearch($s),
            $this->savedSearches->forUser($userId),
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $saved = $this->savedSearches->save(
            $userId,
            $data['name'],
            (string) $request->query('q', $request->input('q', '')),
            $this->searchType($request),
            $this->filters($request),
            $this->sortOption($request),
        );

        return $this->data($this->presenter->savedSearch($saved), 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->savedSearches->delete($this->requireUserId($request), $id);

        return new JsonResponse(null, 204);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        $userId = $this->requireUserId($request);
        $saved = $this->savedSearches->get($userId, $id);

        $query = $this->queryBuilder->build(
            term: $saved->term(),
            type: $saved->type(),
            filters: $saved->filters(),
            sort: $saved->sort(),
            page: (int) $request->query('page', '1'),
            perPage: (int) $request->query('per_page', '0'),
            locale: (string) $request->query('locale', 'en'),
            geo: $this->geoPoint($request),
        );

        $executed = $this->search->search($query, $this->actorIsAdmin($request), $userId);

        return $this->data($this->presenter->results($executed->results, $executed->queryId));
    }
}
