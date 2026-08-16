<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Risk;

/**
 * What the risk evaluator concluded.
 *
 * Three outcomes rather than a score, because a number forces every call site to
 * invent its own threshold and those thresholds then disagree.
 */
final readonly class RiskAssessment
{
    private function __construct(
        public RiskDecision $decision,
        public ?RiskSignalType $signal,
        public ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(RiskDecision::Allow, null, null);
    }

    /** Let it through, but mark it for somebody to look at. */
    public static function review(RiskSignalType $signal, string $reason): self
    {
        return new self(RiskDecision::Review, $signal, $reason);
    }

    /**
     * Refuse outright.
     *
     * Only for signals whose type permits it — blocking a genuine customer at
     * checkout costs more than reviewing a fraudulent one afterwards.
     */
    public static function block(RiskSignalType $signal, string $reason): self
    {
        return $signal->mayBlockSynchronously()
            ? new self(RiskDecision::Block, $signal, $reason)
            : new self(RiskDecision::Review, $signal, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->decision !== RiskDecision::Block;
    }
}
