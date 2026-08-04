<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Ledger;

/** Minimal id source so {@see LedgerPosting} stays framework-free. */
interface IdentityGenerator
{
    public function next(): string;
}
