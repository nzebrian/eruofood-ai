<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Interface\Http\Controller;

use EruoFood\Loyalty\Application\Service\LoyaltyPresenter;
use EruoFood\Loyalty\Application\Service\LoyaltyService;
use EruoFood\Loyalty\Domain\Account\TierPolicy;
use EruoFood\Loyalty\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Loyalty\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The member's loyalty surface: their account, balance, tier progress and points ledger. */
final class LoyaltyController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly LoyaltyService $loyalty,
        private readonly TierPolicy $tiers,
        private readonly LoyaltyPresenter $presenter,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        $account = $this->loyalty->accountFor($this->currentUserId($request));

        return $this->data($this->presenter->account($account));
    }

    public function ledger(Request $request): JsonResponse
    {
        $page = $this->loyalty->ledger(
            $this->currentUserId($request),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($e): array => $this->presenter->entry($e));
    }

    /** The tier ladder (public — powers the "how tiers work" page). */
    public function tiers(): JsonResponse
    {
        return $this->data(array_map(fn ($t): array => $this->presenter->tier($t), $this->tiers->all()));
    }
}
