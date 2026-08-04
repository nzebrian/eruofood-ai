<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user acts on a vendor/order they do not own (and is not admin). */
final class NotVendorOwner extends DomainException
{
    public function __construct(string $message = 'You do not have permission to manage this resource.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'MARKETPLACE_NOT_AUTHORIZED';
    }
}
