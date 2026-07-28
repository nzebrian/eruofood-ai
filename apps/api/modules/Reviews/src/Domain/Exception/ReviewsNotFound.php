<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a review or rating summary is missing. */
final class ReviewsNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'REVIEWS_RESOURCE_NOT_FOUND';
    }
}
