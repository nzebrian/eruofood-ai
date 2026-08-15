<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The offer was already resolved.
 *
 * The genuine race this exists for: a rider taps Accept at the same instant the
 * expiry sweep runs. Exactly one wins, and the other is told plainly rather
 * than being left believing they have a job.
 */
final class OfferNoLongerAnswerable extends DomainException
{
    public static function because(string $state): self
    {
        return new self(sprintf('This offer is no longer open (it is %s).', $state));
    }

    public function errorCode(): string
    {
        return 'DISPATCH_OFFER_NOT_ANSWERABLE';
    }
}
