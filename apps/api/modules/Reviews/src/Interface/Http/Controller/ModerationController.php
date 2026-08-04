<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Interface\Http\Controller;

use EruoFood\Reviews\Application\Service\ModerationService;
use EruoFood\Reviews\Application\Service\ReviewPresenter;
use EruoFood\Reviews\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Reviews\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The moderator workspace: the queue of held reviews and the approve/reject/
 * remove decisions. A role guard runs in every action. Approving publishes and
 * re-projects the subject's rating; rejecting/removing keeps the summary correct.
 */
final class ModerationController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly ModerationService $moderation,
        private readonly ReviewPresenter $presenter,
    ) {
    }

    public function queue(Request $request): JsonResponse
    {
        $this->requireModerator($request);
        $page = $this->moderation->queue(
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($r): array => $this->presenter->moderationView($r));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $moderatorId = $this->requireModerator($request);

        return $this->data($this->presenter->moderationView($this->moderation->approve($id, $moderatorId)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $moderatorId = $this->requireModerator($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->data($this->presenter->moderationView($this->moderation->reject($id, $moderatorId, $data['reason'])));
    }

    public function remove(Request $request, string $id): JsonResponse
    {
        $moderatorId = $this->requireModerator($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->data($this->presenter->moderationView($this->moderation->remove($id, $moderatorId, $data['reason'])));
    }
}
