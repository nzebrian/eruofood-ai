<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * A per-caller rate limit or the platform-wide provider budget is spent.
 *
 * The global variant is a cost control, not a fairness one: mapping APIs bill
 * per request, and a looping mobile client can run up a very large bill very
 * quickly with no malice at all.
 */
final class GeoQuotaExceeded extends DomainException
{
    public static function forCaller(): self
    {
        return new self('Too many location requests. Please try again shortly.');
    }

    public static function forPlatform(): self
    {
        return new self('Location services are temporarily at capacity. Please try again shortly.');
    }

    public function errorCode(): string
    {
        return 'GEO_QUOTA_EXCEEDED';
    }
}
