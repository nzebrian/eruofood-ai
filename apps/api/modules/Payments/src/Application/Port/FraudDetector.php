<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

use EruoFood\Payments\Application\DTO\FraudDecision;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Fraud-detection hook, consulted before a charge is initialized. The default
 * implementation allows everything; a real rules/ML engine can be dropped in
 * behind this port without touching the payment flow.
 */
interface FraudDetector
{
    public function assess(string $userId, Money $amount, string $ipAddress): FraudDecision;
}
