<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Notification;

use EruoFood\Payments\Application\Service\SettlementNotifier;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\SettlementRun;

/**
 * Says nothing, and never fails.
 *
 * Bound when settlement notifications are switched off in configuration. It
 * exists so that "we have not configured channels yet" is a deployment
 * decision rather than a settlement that throws, and so that a test asserting
 * ledger behaviour does not have to stand up a notification stack to do it.
 *
 * Not a default. The real notifier is the default; this is the opt-out.
 */
final class NullSettlementNotifier implements SettlementNotifier
{
    public function settlementSucceeded(SettlementRun $run): void
    {
    }

    public function settlementFailed(SettlementRun $run): void
    {
    }

    public function reconciliationRequired(SettlementRun $run): void
    {
    }

    public function caseOpened(ReconciliationCase $case): void
    {
    }
}
