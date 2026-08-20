<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\SettlementRun;

/**
 * Telling people what happened to their money.
 *
 * A port rather than a direct call to the notification service, so the
 * settlement path depends on the *decision* to notify and not on how. The real
 * implementation goes through M24's `NotificationService`; the null
 * implementation exists so a test can assert a settlement without asserting a
 * notification, and so a deployment that has not configured channels does not
 * fail settlements.
 *
 * ## Nothing here may fail a settlement
 *
 * Every implementation swallows its own errors. A merchant not receiving an
 * email is bad; a completed bank transfer being rolled back because an SMTP
 * server was down is worse, and would leave the money moved and the run
 * un-recorded. This is the specific defect M26 hit — a notifier resolved
 * outside the try/catch — so the rule is stated here rather than assumed.
 */
interface SettlementNotifier
{
    public function settlementSucceeded(SettlementRun $run): void;

    public function settlementFailed(SettlementRun $run): void;

    /** Operations only. A merchant must never be told "we might have paid you". */
    public function reconciliationRequired(SettlementRun $run): void;

    /** Operations only. */
    public function caseOpened(ReconciliationCase $case): void;
}
