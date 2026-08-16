<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Risk;

use EruoFood\Shared\Domain\Risk\RiskAssessment;
use EruoFood\Shared\Domain\Risk\RiskEvaluator;
use EruoFood\Shared\Domain\Risk\RiskSignalType;
use EruoFood\Shared\Domain\Risk\RiskSubject;

/**
 * The evaluator that ships: allows everything, records nothing.
 *
 * This is the honest default until M29 exists. It is deliberately *not* a
 * partial implementation — a half-built fraud detector is worse than none,
 * because people start trusting its output before it is trustworthy.
 *
 * It also documents the required failure behaviour by embodying it: allow, do
 * not throw, do not block food from reaching customers.
 */
final readonly class NullRiskEvaluator implements RiskEvaluator
{
    public function evaluate(RiskSubject $subject, RiskSignalType $type): RiskAssessment
    {
        return RiskAssessment::allow();
    }

    /** @param array<string, scalar|null> $context identifiers and counts only — never personal data */
    public function observe(RiskSubject $subject, RiskSignalType $type, array $context = []): void
    {
        // Deliberately empty. Writing signals nobody reads would build a table
        // of personal movement and payment data with no consumer to justify it.
    }
}
