<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Input\DiaryEntryInput;
use EruoFood\Nutrition\Application\Service\DiaryService;
use EruoFood\Nutrition\Application\Service\NutritionPresenter;
use EruoFood\Nutrition\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\DiaryEntryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daily nutrient tracking (food diary). */
final readonly class DiaryController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private DiaryService $diary,
        private NutritionPresenter $presenter,
    ) {
    }

    /** Log a food for a day + meal. */
    public function store(DiaryEntryRequest $request): JsonResponse
    {
        $entry = $this->diary->log(
            $this->currentUserId($request),
            DiaryEntryInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->diaryEntry($entry), 201);
    }

    /** A day's entries, totals and targets (defaults to today). */
    public function day(Request $request): JsonResponse
    {
        $date = ((string) $request->string('date')) ?: date('Y-m-d');
        $summary = $this->diary->day($this->currentUserId($request), $date);

        return $this->data($this->presenter->dailySummary($summary));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->diary->delete($this->currentUserId($request), $id);

        return new JsonResponse(null, 204);
    }
}
