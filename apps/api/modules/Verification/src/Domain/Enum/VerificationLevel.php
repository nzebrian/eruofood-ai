<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * How strongly an account's identity is established.
 *
 * Progressive by design: an ordinary customer registers at Basic and is never
 * forced further. Higher levels are demanded only when an operation or a risk
 * signal calls for it (step-up), or when the role requires it outright (riders,
 * business representatives).
 */
enum VerificationLevel: string
{
    /** Email verified. What ordinary registration produces. */
    case Basic = 'basic';

    /** Email + phone verified. */
    case Phone = 'phone';

    /** Government identity document verified by a provider. */
    case Identity = 'identity';

    /** Ranking, so "is this level at least X" is a comparison rather than a match. */
    public function rank(): int
    {
        return match ($this) {
            self::Basic => 1,
            self::Phone => 2,
            self::Identity => 3,
        };
    }

    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
