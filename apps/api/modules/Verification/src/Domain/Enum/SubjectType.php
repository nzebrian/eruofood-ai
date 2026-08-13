<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Enum;

/**
 * Who is being verified.
 *
 * A verification case references its subject as (type, id) — a soft reference
 * into the owning context, the same pattern Reviews uses. Verification never
 * joins to Identity, Marketplace or Commerce tables.
 */
enum SubjectType: string
{
    case Customer = 'customer';
    case Rider = 'rider';
    case Business = 'business';

    /** The verification level this subject must reach to be operational. */
    public function requiredLevel(): VerificationLevel
    {
        return match ($this) {
            // Customers are progressive: registration alone is enough.
            self::Customer => VerificationLevel::Basic,
            self::Rider, self::Business => VerificationLevel::Identity,
        };
    }
}
