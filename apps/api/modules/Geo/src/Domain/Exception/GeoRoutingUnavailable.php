<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * No billable distance could be established.
 *
 * Raised at the end of the fallback chain, when a fresh route, a usable cached
 * route and a merchant flat zone fee have all been exhausted. It exists so that
 * refusing to quote is an explicit, testable outcome rather than something that
 * degrades quietly into a straight-line guess.
 *
 * Declining to price a delivery is a poor experience. Charging confidently for
 * a distance nobody measured is worse, and at scale it is a systematic,
 * one-directional error nobody notices.
 */
final class GeoRoutingUnavailable extends DomainException
{
    public function __construct(
        string $message = 'Delivery pricing is temporarily unavailable for this address. Please try again shortly, or choose pickup.',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'GEO_ROUTING_UNAVAILABLE';
    }
}
