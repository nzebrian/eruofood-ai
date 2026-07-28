<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Controller;

use EruoFood\Search\Application\Service\SearchAnalyticsService;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Application\Service\SearchPresenter;
use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Exception\SearchNotAuthorized;
use EruoFood\Search\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Search\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin search analytics dashboards and on-demand reindex (RBAC: admin role). */
final class SearchAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly SearchAnalyticsService $analytics,
        private readonly SearchIndexManager $indexManager,
        private readonly SearchPresenter $presenter,
    ) {
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return $this->data($this->analytics->metrics($this->days($request))->toArray());
    }

    public function popular(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return $this->data(['terms' => array_map(
            fn (PopularTerm $t): array => $this->presenter->popularTerm($t),
            $this->analytics->popular($this->days($request), 20),
        )]);
    }

    public function failed(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return $this->data(['terms' => array_map(
            fn (PopularTerm $t): array => $this->presenter->popularTerm($t),
            $this->analytics->failed($this->days($request), 20),
        )]);
    }

    public function reindex(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $type = $request->input('type');
        $count = $this->indexManager->reindexAll(is_string($type) && $type !== '' ? $type : null);

        return $this->data(['reindexed' => $count]);
    }

    private function assertAdmin(Request $request): void
    {
        if (! $this->actorIsAdmin($request)) {
            throw new SearchNotAuthorized('Administrator role required.');
        }
    }

    private function days(Request $request): int
    {
        return max(1, min(365, (int) $request->query('days', '30')));
    }
}
