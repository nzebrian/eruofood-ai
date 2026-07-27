<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Support;

use EruoFood\Payments\Domain\Ledger\IdentityGenerator;
use Illuminate\Support\Str;

/** Ordered-UUID id source for the framework-free ledger domain service. */
final class UuidIdentityGenerator implements IdentityGenerator
{
    public function next(): string
    {
        return (string) Str::orderedUuid();
    }
}
