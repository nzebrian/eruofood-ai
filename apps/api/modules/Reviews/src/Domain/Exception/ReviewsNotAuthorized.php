<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a caller acts on a review they do not own or moderate. */
final class ReviewsNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'REVIEWS_NOT_AUTHORIZED';
    }
}
