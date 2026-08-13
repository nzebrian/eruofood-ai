<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Ledger;

/**
 * The outcome of a ledger integrity check.
 *
 * In a healthy ledger every correlation's debits and credits cancel, so both
 * {@see $netMinor} and {@see $unbalancedCorrelationIds} are empty/zero. Anything
 * else is a finance incident, not a warning.
 */
final readonly class LedgerIntegrityReport
{
    /** @param list<string> $unbalancedCorrelationIds */
    public function __construct(
        public int $correlationsChecked,
        public int $netMinor,
        public array $unbalancedCorrelationIds,
    ) {
    }

    public function isBalanced(): bool
    {
        return $this->netMinor === 0 && $this->unbalancedCorrelationIds === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'balanced' => $this->isBalanced(),
            'correlations_checked' => $this->correlationsChecked,
            'net_minor' => $this->netMinor,
            'unbalanced_correlation_ids' => $this->unbalancedCorrelationIds,
        ];
    }
}
