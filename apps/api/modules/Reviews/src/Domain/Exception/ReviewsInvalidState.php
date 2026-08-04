<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an invalid rating, moderation transition, or disallowed action. */
final class ReviewsInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'REVIEWS_INVALID_STATE';
    }
}
