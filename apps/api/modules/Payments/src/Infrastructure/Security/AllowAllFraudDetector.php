<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Security;

use EruoFood\Payments\Application\DTO\FraudDecision;
use EruoFood\Payments\Application\Port\FraudDetector;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The default fraud hook — allows every charge. It exists so the payment flow
 * always consults a fraud port; a rules/ML detector can be bound in its place
 * without touching callers.
 */
final class AllowAllFraudDetector implements FraudDetector
{
    public function assess(string $userId, Money $amount, string $ipAddress): FraudDecision
    {
        return FraudDecision::allow();
    }
}
