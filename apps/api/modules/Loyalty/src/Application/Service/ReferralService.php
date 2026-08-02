<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Event\ReferralQualified;
use EruoFood\Loyalty\Domain\Exception\LoyaltyConflict;
use EruoFood\Loyalty\Domain\Exception\LoyaltyNotFound;
use EruoFood\Loyalty\Domain\Referral\Referral;
use EruoFood\Loyalty\Domain\Referral\ReferralCode;
use EruoFood\Loyalty\Domain\Referral\ReferralRepository;
use EruoFood\Shared\Domain\EventBus;

/**
 * The referral programme. It issues a member's shareable code, attributes a new
 * member to a referrer (guarding self-referral and one-referrer-per-referee), and
 * qualifies a pending referral when the referee triggers the qualifying event —
 * awarding both sides through {@see LoyaltyService} and publishing
 * {@see ReferralQualified}. Referrals never touch another context's tables.
 */
final readonly class ReferralService
{
    public function __construct(
        private ReferralRepository $referrals,
        private LoyaltyService $loyalty,
        private EventBus $events,
        private int $referrerPoints,
        private int $refereePoints,
    ) {
    }

    /** The member's personal referral code, creating it on first request. */
    public function codeFor(string $userId): ReferralCode
    {
        $existing = $this->referrals->findCodeByUser($userId);
        if ($existing !== null) {
            return $existing;
        }
        $code = new ReferralCode($this->referrals->generateCode(), $userId, new DateTimeImmutable());
        $this->referrals->saveCode($code);

        return $code;
    }

    /** Attribute a referee to the owner of `$code`. */
    public function apply(string $refereeUserId, string $code): Referral
    {
        $referralCode = $this->referrals->findCodeByCode($code) ?? throw LoyaltyNotFound::of('referral code', $code);
        if ($this->referrals->hasReferee($refereeUserId)) {
            throw new LoyaltyConflict('You have already used a referral code.');
        }

        // Referral::attribute rejects self-referral.
        $referral = Referral::attribute(
            $this->referrals->nextIdentity(),
            $referralCode->code,
            $referralCode->userId,
            $refereeUserId,
            new DateTimeImmutable(),
        );
        $this->referrals->save($referral);

        return $referral;
    }

    /**
     * Qualify the referee's pending referral, if any, rewarding both sides.
     * Returns the qualified referral, or null when there is nothing to qualify.
     */
    public function qualify(string $refereeUserId): ?Referral
    {
        $referral = $this->referrals->pendingByReferee($refereeUserId);
        if ($referral === null) {
            return null;
        }

        $referral->qualify(new DateTimeImmutable());
        $this->referrals->save($referral);

        $this->loyalty->earn($referral->referrerUserId(), $this->referrerPoints, 'referral', $referral->id());
        $this->loyalty->earn($referral->refereeUserId(), $this->refereePoints, 'referral', $referral->id());

        $this->events->publish(new ReferralQualified(
            $referral->id(),
            $referral->referrerUserId(),
            $referral->refereeUserId(),
            $this->referrerPoints,
            $this->refereePoints,
        ));

        return $referral;
    }
}
