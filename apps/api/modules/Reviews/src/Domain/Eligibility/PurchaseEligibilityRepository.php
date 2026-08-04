<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Eligibility;

use EruoFood\Reviews\Domain\ValueObject\Subject;

/**
 * The verified-purchase ledger. Reviews records (user, subject) eligibility from
 * published order events — the only way it learns who bought what — and checks it
 * when a review is submitted to stamp `verified_purchase`. A soft, event-fed
 * record; Reviews never queries an order table.
 */
interface PurchaseEligibilityRepository
{
    public function record(string $userId, Subject $subject): void;

    public function isEligible(string $userId, Subject $subject): bool;
}
