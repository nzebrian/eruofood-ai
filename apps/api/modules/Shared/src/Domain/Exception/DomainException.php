<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Exception;

use RuntimeException;

/**
 * Base type for all domain-level exceptions.
 *
 * Domain exceptions express broken invariants or business-rule violations in
 * the ubiquitous language. The interface layer maps them to RFC 7807 problem
 * responses, keeping HTTP concerns out of the domain.
 */
abstract class DomainException extends RuntimeException
{
    /** Stable, machine-readable error code, e.g. "ORDER_NOT_FOUND". */
    abstract public function errorCode(): string;
}
