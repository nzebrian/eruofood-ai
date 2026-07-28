<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Enum;

/**
 * The moderation lifecycle of a review. Only Published reviews are visible to
 * the public and count toward a subject's rating summary.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';     // awaiting moderation
    case Published = 'published'; // live and counted
    case Rejected = 'rejected';   // moderation declined it
    case Removed = 'removed';     // taken down after publication

    /** Whether a review in this state contributes to the rating summary. */
    public function countsToRating(): bool
    {
        return $this === self::Published;
    }

    public function isVisible(): bool
    {
        return $this === self::Published;
    }
}
