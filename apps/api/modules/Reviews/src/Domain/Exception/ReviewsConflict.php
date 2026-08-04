<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user reviews the same subject twice. */
final class ReviewsConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'REVIEWS_CONFLICT';
    }
}
