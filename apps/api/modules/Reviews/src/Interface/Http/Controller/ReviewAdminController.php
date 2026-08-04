<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Interface\Http\Controller;

use EruoFood\Reviews\Application\Service\ReviewAnalyticsService;
use EruoFood\Reviews\Application\Service\ReviewPresenter;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Reviews\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin analytics over the review corpus: the moderation funnel and top-rated subjects. */
final class ReviewAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly ReviewAnalyticsService $analytics,
        private readonly ReviewPresenter $presenter,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $this->requireModerator($request);

        return $this->data($this->analytics->overview());
    }

    public function topRated(Request $request, string $subjectType): JsonResponse
    {
        $this->requireModerator($request);
        $type = SubjectType::tryFrom($subjectType)
            ?? throw new \EruoFood\Reviews\Domain\Exception\ReviewsInvalidState('Unknown subject type: '.$subjectType);

        $summaries = $this->analytics->topRated(
            $type,
            (int) $request->query('min_count', '1'),
            (int) $request->query('limit', '10'),
        );

        return $this->data(array_map(fn ($s): array => $this->presenter->summary($s), $summaries));
    }
}
