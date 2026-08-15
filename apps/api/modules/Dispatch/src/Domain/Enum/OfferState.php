<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/**
 * The life of one offer to one rider.
 *
 * `Offered` is the only non-terminal state, and that is what makes the partial
 * unique index on `(rider_id) WHERE state = 'offered'` meaningful: a rider can
 * be looking at exactly one offer at a time.
 */
enum OfferState: string
{
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /** Nobody answered before the TTL ran out. */
    case Expired = 'expired';

    /** Withdrawn by the platform — the order was cancelled, or the rider went offline. */
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this !== self::Offered;
    }

    /**
     * Whether this offer may still be answered.
     *
     * The check that decides a genuine race: a rider tapping Accept at the same
     * instant the expiry sweep runs. Exactly one of them wins, and this is
     * where that is settled.
     */
    public function isAnswerable(): bool
    {
        return $this === self::Offered;
    }
}
