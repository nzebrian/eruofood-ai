<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

use EruoFood\Loyalty\Domain\Enum\LedgerEntryType;

/** A filter over a member's points-ledger entries, newest first. */
final readonly class LedgerQuery
{
    public function __construct(
        public string $accountId,
        public ?LedgerEntryType $type = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
