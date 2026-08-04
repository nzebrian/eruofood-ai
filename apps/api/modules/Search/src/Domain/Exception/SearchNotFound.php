<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a search resource (a saved search, a document) is missing. */
final class SearchNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'SEARCH_RESOURCE_NOT_FOUND';
    }
}
