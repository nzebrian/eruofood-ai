<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Domain\Ledger\LedgerIntegrityReport;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;

/**
 * Proves the double-entry ledger still balances.
 *
 * {@see \EruoFood\Payments\Domain\Ledger\LedgerPosting::balanced()} refuses to
 * write an unbalanced group, so in a correct system this check can only pass.
 * That is exactly why it is worth running: a failure means something bypassed
 * the domain — a partially-committed posting, a manual correction, a bug in a
 * new code path — and the sooner finance hears about it the smaller the
 * reconciliation.
 *
 * Run it as a scheduled job and after any incident touching payments.
 */
final readonly class LedgerIntegrityService
{
    public function __construct(private LedgerRepository $ledger)
    {
    }

    public function verify(): LedgerIntegrityReport
    {
        $unbalanced = $this->ledger->unbalancedCorrelations();

        return new LedgerIntegrityReport(
            correlationsChecked: $this->ledger->correlationCount(),
            netMinor: $this->ledger->netMinor(),
            unbalancedCorrelationIds: $unbalanced,
        );
    }
}
