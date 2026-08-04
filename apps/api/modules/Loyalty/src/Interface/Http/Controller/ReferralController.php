<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Interface\Http\Controller;

use EruoFood\Loyalty\Application\Service\LoyaltyPresenter;
use EruoFood\Loyalty\Application\Service\ReferralService;
use EruoFood\Loyalty\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Loyalty\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The referral programme: get your shareable code and apply someone else's. */
final class ReferralController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly ReferralService $referrals,
        private readonly LoyaltyPresenter $presenter,
    ) {
    }

    /** The caller's personal referral code (created on first request). */
    public function myCode(Request $request): JsonResponse
    {
        $code = $this->referrals->codeFor($this->currentUserId($request));

        return $this->data($this->presenter->referralCode($code));
    }

    /** Apply a referrer's code — attributes the caller as a referee. */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $referral = $this->referrals->apply($this->currentUserId($request), $data['code']);

        return $this->data($this->presenter->referral($referral), 201);
    }
}
