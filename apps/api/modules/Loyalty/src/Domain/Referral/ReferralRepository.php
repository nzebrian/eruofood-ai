<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Referral;

/** Persistence port for referral codes and attributions. */
interface ReferralRepository
{
    public function nextIdentity(): string;

    /** A unique, shareable referral code (retryable on collision). */
    public function generateCode(): string;

    public function findCodeByUser(string $userId): ?ReferralCode;

    public function findCodeByCode(string $code): ?ReferralCode;

    public function saveCode(ReferralCode $code): void;

    /** Whether this referee has already been attributed to any referrer. */
    public function hasReferee(string $refereeUserId): bool;

    /** The referee's pending attribution awaiting a qualifying event, if any. */
    public function pendingByReferee(string $refereeUserId): ?Referral;

    public function save(Referral $referral): void;
}
