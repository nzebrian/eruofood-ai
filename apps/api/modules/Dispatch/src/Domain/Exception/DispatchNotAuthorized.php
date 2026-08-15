<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** The caller may not perform this dispatch action. */
final class DispatchNotAuthorized extends DomainException
{
    public function __construct(string $message = 'You are not permitted to perform this dispatch action.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'DISPATCH_NOT_AUTHORIZED';
    }
}
