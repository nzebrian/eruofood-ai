<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Referral;

use DateTimeImmutable;

/** A member's personal referral code — the token they share to invite others. */
final readonly class ReferralCode
{
    public function __construct(
        public string $code,
        public string $userId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
