<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Geo;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\RiderPresence;
use EruoFood\Geo\Domain\Rider\RiderLocationRepository;

/**
 * Rider presence, read from M25's position records.
 *
 * A soft-referenced read across the context boundary, exactly like
 * {@see GeoCandidateSource}: Dispatch asks Geo a question and stores nothing of
 * its own. Keeping one source of "when did we last hear from this rider" is
 * what stops two components disagreeing about whether somebody is available.
 */
final readonly class GeoRiderPresence implements RiderPresence
{
    public function __construct(private RiderLocationRepository $locations)
    {
    }

    public function lastSeenAt(string $riderId): ?DateTimeImmutable
    {
        $location = $this->locations->findByRider($riderId);

        return $location?->recordedAt();
    }
}
