<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Controller;

use EruoFood\Search\Application\Service\RecommendationService;
use EruoFood\Search\Application\Service\SearchPresenter;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Enum\RecommendationType;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Search\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The recommendation widgets: related/similar/trending/seasonal/restaurant and personalised. */
final class RecommendationController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    private const MAX_LIMIT = 24;

    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly SearchPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $kind = RecommendationType::tryFrom((string) $request->query('kind', 'trending')) ?? RecommendationType::Trending;
        $type = SearchType::tryFrom((string) $request->query('type', 'food')) ?? SearchType::Food;
        $anchorId = $request->query('anchor_id');
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query('limit', '8')));

        $documents = $this->recommendations->recommend(
            $kind,
            $type,
            is_string($anchorId) ? $anchorId : null,
            $this->optionalUserId($request),
            $limit,
            isAdmin: $this->actorIsAdmin($request),
        );

        return $this->respond($kind, $documents);
    }

    /** Personalised recommendations for the authenticated user. */
    public function personalised(Request $request): JsonResponse
    {
        $type = SearchType::tryFrom((string) $request->query('type', 'food')) ?? SearchType::Food;
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query('limit', '8')));

        $documents = $this->recommendations->recommend(
            RecommendationType::Personalised,
            $type,
            null,
            $this->requireUserId($request),
            $limit,
            isAdmin: $this->actorIsAdmin($request),
        );

        return $this->respond(RecommendationType::Personalised, $documents);
    }

    /**
     * @param list<SearchDocument> $documents
     */
    private function respond(RecommendationType $kind, array $documents): JsonResponse
    {
        return $this->data([
            'kind' => $kind->value,
            'items' => array_map(fn (SearchDocument $d): array => $this->presenter->document($d), $documents),
        ]);
    }
}
