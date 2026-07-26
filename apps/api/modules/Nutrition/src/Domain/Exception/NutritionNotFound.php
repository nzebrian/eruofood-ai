<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a nutrition resource (item, plan, entry) is missing or not the caller's. */
final class NutritionNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'NUTRITION_RESOURCE_NOT_FOUND';
    }
}
