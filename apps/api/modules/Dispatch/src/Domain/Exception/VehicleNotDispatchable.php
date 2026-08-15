<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** The vehicle cannot be used for work — unverified, suspended, or out of date. */
final class VehicleNotDispatchable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function errorCode(): string
    {
        return 'DISPATCH_VEHICLE_NOT_DISPATCHABLE';
    }
}
