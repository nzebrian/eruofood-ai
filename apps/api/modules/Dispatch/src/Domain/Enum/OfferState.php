<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

use EruoFood\Shared\Domain\Lifecycle\ServerAuthoritative;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;

/**
 * The life of one offer to one rider.
 *
 * `Offered` is the only non-terminal state, and that is what makes the partial
 * unique index on `(rider_id) WHERE state = 'offered'` meaningful: a rider can
 * be looking at exactly one offer at a time.
 */
enum OfferState: string implements ServerAuthoritative
{
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /** Nobody answered before the TTL ran out. */
    case Expired = 'expired';

    /** Withdrawn by the platform — the order was cancelled, or the rider went offline. */
    case Cancelled = 'cancelled';

    /**
     * Where an offer has got to.
     *
     * `Expired` maps to its own phase rather than to `Failed`. A rider who ran
     * out of time and a rider who said no are different facts, and only one of
     * them is a decision — which matters both for what the app says and for
     * what fairness scoring later makes of it.
     *
     * `Declined` is `Cancelled`: the rider decided.
     */
    public function serverPhase(): ServerPhase
    {
        return match ($this) {
            self::Offered => ServerPhase::Pending,
            self::Accepted => ServerPhase::Confirmed,
            self::Declined, self::Cancelled => ServerPhase::Cancelled,
            self::Expired => ServerPhase::Expired,
        };
    }

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
