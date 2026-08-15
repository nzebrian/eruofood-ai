<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * A dispatch resource does not exist, or does not belong to the caller.
 *
 * Deliberately the same answer for both. An offer or an assignment is addressed
 * by UUID, and replying "that exists but is not yours" turns the endpoint into
 * a way of discovering other riders' work.
 */
final class DispatchNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('No %s was found.', $resource));
    }

    public function errorCode(): string
    {
        return 'DISPATCH_RESOURCE_NOT_FOUND';
    }
}
