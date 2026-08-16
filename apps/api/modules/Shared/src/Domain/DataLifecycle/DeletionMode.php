<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\DataLifecycle;

/**
 * How a record's life ends.
 *
 * Three different endings, because "delete it" is the right answer for a GPS
 * trail, the wrong answer for an order that still has to appear in last year's
 * totals, and an illegal answer for a ledger inside its statutory period.
 */
enum DeletionMode: string
{
    /** The row goes. Right for data whose only value was operational. */
    case Destroy = 'destroy';

    /**
     * The row stays; the person is removed from it.
     *
     * An order history that still has to add up, with no customer attached.
     * Only permitted for categories that survive losing the identity — see
     * {@see DataCategory::supportsAnonymisation()}.
     */
    case Anonymise = 'anonymise';

    /**
     * Moved out of the working set, kept intact.
     *
     * For data under a statutory hold: it must not be in the live database and
     * must not be destroyed either.
     */
    case Archive = 'archive';

    public function isReversible(): bool
    {
        // Only archival. This is why a retention purge dry-run is not optional:
        // the other two cannot be undone by anybody, at any price.
        return $this === self::Archive;
    }

    public function explain(): string
    {
        return match ($this) {
            self::Destroy => 'The record is deleted outright.',
            self::Anonymise => 'The record is kept with the person removed from it.',
            self::Archive => 'The record leaves the working set intact, under a statutory hold.',
        };
    }
}
