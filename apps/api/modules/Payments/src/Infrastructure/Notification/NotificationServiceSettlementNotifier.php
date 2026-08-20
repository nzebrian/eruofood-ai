<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Notification;

use EruoFood\Notifications\Application\Service\NotificationService;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Payments\Application\Port\MerchantDirectory;
use EruoFood\Payments\Application\Service\SettlementNotifier;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Settlement notifications through M24's notification service.
 *
 * This is what replaces `LoggingPaymentNotifier` for the settlement path.
 * Merchants were previously told nothing about their money — the "notifier" was
 * a log line nobody outside the platform can read.
 *
 * ## What reaches a merchant, and what does not
 *
 * A merchant is told when a settlement **succeeded** or **failed**, with the
 * amount and the settlement reference. That is all. They are never told about
 * an unknown outcome: "we may or may not have paid you" is not information, it
 * is alarm, and the honest thing is to establish the answer first and then say
 * it once.
 *
 * No provider reference, no bank details, no provider message. A provider
 * reference is an operational identifier that means nothing to a merchant and
 * can appear in a support conversation the platform does not control.
 *
 * ## Operations notifications go nowhere yet, deliberately
 *
 * `reconciliationRequired()` and `caseOpened()` have no recipient list, because
 * the platform has no operations-team notification group and inventing one here
 * would pick recipients nobody agreed to. They log at warning level, which is
 * where the operator dashboard reads from, and the reconciliation queue is the
 * real surface. Recorded in the pending-items document rather than left to be
 * discovered.
 *
 * ## Failures are swallowed
 *
 * Every method catches. See {@see SettlementNotifier} for why this is the one
 * place that rule is correct.
 */
final readonly class NotificationServiceSettlementNotifier implements SettlementNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private MerchantDirectory $merchants,
        private LoggerInterface $log,
    ) {
    }

    public function settlementSucceeded(SettlementRun $run): void
    {
        $this->tellMerchant($run, 'settlement.succeeded', [
            NotificationChannel::Push,
            NotificationChannel::InApp,
            NotificationChannel::Email,
        ]);
    }

    public function settlementFailed(SettlementRun $run): void
    {
        $this->tellMerchant($run, 'settlement.failed', [
            NotificationChannel::InApp,
            NotificationChannel::Email,
        ]);
    }

    public function reconciliationRequired(SettlementRun $run): void
    {
        // Operations only, and the merchant is deliberately not copied.
        $this->log->warning('payments.settlement.reconciliation_required', [
            'settlement_run_id' => $run->id(),
            'settlement_reference' => $run->settlementReference(),
            'merchant_type' => $run->merchantType(),
            'merchant_id' => $run->merchantId(),
            'amount_minor' => $run->net()->minorUnits,
            'currency' => $run->currency(),
            'correlation_id' => $run->correlationId(),
        ]);
    }

    public function caseOpened(ReconciliationCase $case): void
    {
        $this->log->warning('payments.reconciliation.case_opened', [
            'case_id' => $case->id(),
            'kind' => $case->kind()->value,
            'subject_type' => $case->subjectType(),
            'subject_id' => $case->subjectId(),
            'difference_minor' => $case->differenceMinor(),
            'currency' => $case->expected()->currency,
            'correlation_id' => $case->correlationId(),
        ]);
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    private function tellMerchant(SettlementRun $run, string $template, array $channels): void
    {
        try {
            $userId = $this->merchants->ownerOf($run->merchantType(), $run->merchantId());
            if ($userId === null) {
                // Nobody to tell. Not an error — a driver payee has no vendor
                // record — and emphatically not a reason to broadcast.
                return;
            }

            $this->notifications->notify(
                userId: $userId,
                category: NotificationCategory::Payment,
                templateKey: $template,
                data: [
                    'settlement_reference' => $run->settlementReference(),
                    'amount_minor' => $run->net()->minorUnits,
                    'currency' => $run->currency(),
                    'window_start' => $run->windowStart()->format(DATE_ATOM),
                    'window_end' => $run->windowEnd()->format(DATE_ATOM),
                ],
                channels: $channels,
                priority: Priority::High,
                correlationId: $run->correlationId(),
            );
        } catch (Throwable $e) {
            $this->log->error('payments.settlement.notify_failed', [
                'settlement_run_id' => $run->id(),
                'template' => $template,
                // The class, not the message: an exception from a channel
                // adapter can carry a recipient address or a provider token.
                'exception' => $e::class,
            ]);
        }
    }
}
