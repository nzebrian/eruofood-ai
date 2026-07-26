<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Input\ProgressInput;
use EruoFood\Nutrition\Application\Service\NutritionPresenter;
use EruoFood\Nutrition\Application\Service\ProgressService;
use EruoFood\Nutrition\Domain\Progress\ProgressEntry;
use EruoFood\Nutrition\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\ProgressRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Progress tracking: record and list body-weight measurements. */
final readonly class ProgressController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ProgressService $progress,
        private NutritionPresenter $presenter,
    ) {
    }

    public function store(ProgressRequest $request): JsonResponse
    {
        $entry = $this->progress->record(
            $this->currentUserId($request),
            ProgressInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->progress($entry), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $history = array_map(
            fn (ProgressEntry $e): array => $this->presenter->progress($e),
            $this->progress->history($this->currentUserId($request), (int) $request->integer('limit', 90)),
        );

        return $this->data($history);
    }
}
