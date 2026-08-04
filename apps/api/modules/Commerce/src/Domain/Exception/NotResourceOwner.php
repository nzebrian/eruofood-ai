<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user acts on a store/product/order they do not own (and is not admin). */
final class NotResourceOwner extends DomainException
{
    public function __construct(string $message = 'You do not have permission to manage this resource.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'COMMERCE_NOT_AUTHORIZED';
    }
}
