<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a catalogue resource (food, recipe, category, ingredient) is missing. */
final class CatalogNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'CATALOG_RESOURCE_NOT_FOUND';
    }
}
