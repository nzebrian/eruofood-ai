<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Interface\Http\Controller;

use EruoFood\Loyalty\Application\Service\LoyaltyPresenter;
use EruoFood\Loyalty\Application\Service\RedemptionService;
use EruoFood\Loyalty\Application\Service\RewardService;
use EruoFood\Loyalty\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Loyalty\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The rewards catalogue and redemption: browse rewards, redeem points, view/cancel your redemptions. */
final class RewardController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly RewardService $rewards,
        private readonly RedemptionService $redemptions,
        private readonly LoyaltyPresenter $presenter,
    ) {
    }

    /** Public — the active rewards catalogue. */
    public function index(Request $request): JsonResponse
    {
        $page = $this->rewards->catalogue(
            true,
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($r): array => $this->presenter->reward($r));
    }

    public function redeem(Request $request, string $rewardId): JsonResponse
    {
        $redemption = $this->redemptions->redeem($this->currentUserId($request), $rewardId);

        return $this->data($this->presenter->redemption($redemption), 201);
    }

    public function myRedemptions(Request $request): JsonResponse
    {
        $page = $this->redemptions->forUser(
            $this->currentUserId($request),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($r): array => $this->presenter->redemption($r));
    }

    public function cancel(Request $request, string $redemptionId): JsonResponse
    {
        $redemption = $this->redemptions->cancel($redemptionId, $this->currentUserId($request));

        return $this->data($this->presenter->redemption($redemption));
    }
}
