<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Loyalty\Application\Service\LoyaltyAnalyticsService;
use EruoFood\Loyalty\Application\Service\LoyaltyPresenter;
use EruoFood\Loyalty\Application\Service\LoyaltyService;
use EruoFood\Loyalty\Application\Service\RedemptionService;
use EruoFood\Loyalty\Application\Service\RewardService;
use EruoFood\Loyalty\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Loyalty\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin surface: adjust members' points, manage the rewards catalogue, fulfil redemptions, view analytics. */
final class LoyaltyAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly LoyaltyService $loyalty,
        private readonly RewardService $rewards,
        private readonly RedemptionService $redemptions,
        private readonly LoyaltyAnalyticsService $analytics,
        private readonly LoyaltyPresenter $presenter,
    ) {
    }

    public function adjust(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'points' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $account = $this->loyalty->adjust($data['user_id'], (int) $data['points'], $data['reason']);

        return $this->data($this->presenter->account($account));
    }

    public function listRewards(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $page = $this->rewards->catalogue(
            false,
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($r): array => $this->presenter->reward($r));
    }

    public function createReward(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'benefit_type' => ['required', 'string', 'max:50'],
            'benefit_value' => ['nullable', 'integer', 'min:0'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $reward = $this->rewards->create(
            $data['name'],
            $data['description'] ?? '',
            $data['benefit_type'],
            (int) ($data['benefit_value'] ?? 0),
            (int) $data['points_cost'],
            isset($data['stock']) ? (int) $data['stock'] : null,
            $this->date($data['starts_at'] ?? null),
            $this->date($data['ends_at'] ?? null),
        );

        return $this->data($this->presenter->reward($reward), 201);
    }

    public function updateReward(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'benefit_type' => ['sometimes', 'string', 'max:50'],
            'benefit_value' => ['sometimes', 'integer', 'min:0'],
            'points_cost' => ['sometimes', 'integer', 'min:1'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $changes = $data;
        if (array_key_exists('starts_at', $data)) {
            $changes['starts_at'] = $this->date($data['starts_at']);
        }
        if (array_key_exists('ends_at', $data)) {
            $changes['ends_at'] = $this->date($data['ends_at']);
        }

        $reward = $this->rewards->update($id, $changes);

        return $this->data($this->presenter->reward($reward));
    }

    public function fulfil(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $redemption = $this->redemptions->fulfill($data['code']);

        return $this->data($this->presenter->redemption($redemption));
    }

    public function analytics(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return $this->data($this->analytics->overview((int) $request->query('top', '5')));
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        return $value !== null && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
