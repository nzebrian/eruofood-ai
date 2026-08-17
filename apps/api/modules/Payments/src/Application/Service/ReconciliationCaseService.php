<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Domain\Enum\ReconciliationState;
use EruoFood\Payments\Domain\Event\FinancialActionAudited;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\ReconciliationCaseRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * What a person does with a discrepancy.
 *
 * Separate from {@see SettlementReconciliationService}, which is what the
 * *machine* does. The split is the design: one class can open a case and close
 * a matched one, the other can adjust the books, and only the second requires a
 * named human on every path that changes a number.
 */
final readonly class ReconciliationCaseService
{
    public function __construct(
        private ReconciliationCaseRepository $cases,
        private LedgerRepository $ledger,
        private TransactionManager $transactions,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function get(string $caseId): ReconciliationCase
    {
        return $this->cases->findById($caseId) ?? throw PaymentsNotFound::of('reconciliation case', $caseId);
    }

    /** @return Paginated<ReconciliationCase> */
    public function list(?ReconciliationState $state, int $page, int $perPage): Paginated
    {
        return $this->cases->all($state, max(1, $page), min(100, max(1, $perPage)));
    }

    public function investigate(string $actorId, string $caseId): ReconciliationCase
    {
        return $this->mutate($actorId, $caseId, 'finance.reconciliation_investigating', function (ReconciliationCase $case): void {
            $case->beginInvestigation($this->clock->now());
        });
    }

    public function escalate(string $actorId, string $caseId, string $note): ReconciliationCase
    {
        return $this->mutate($actorId, $caseId, 'finance.reconciliation_escalated', function (ReconciliationCase $case) use ($note): void {
            $case->escalate($note, $this->clock->now());
        });
    }

    /**
     * Close a case because the two sides turned out to agree.
     *
     * Always with a named resolver here — the unattended path lives in the
     * reconciler and is restricted to the one discrepancy kind that can honestly
     * resolve itself.
     */
    public function resolveMatched(string $actorId, string $caseId, string $note): ReconciliationCase
    {
        return $this->mutate($actorId, $caseId, 'finance.reconciliation_resolved_matched', function (ReconciliationCase $case) use ($actorId, $note): void {
            $case->resolveMatched($actorId, $note, $this->clock->now());
        });
    }

    /**
     * Close a case by pointing at a compensating posting somebody made.
     *
     * The posting must already exist. This method does not create it, and will
     * not accept an id that does not resolve to real ledger entries — a
     * resolution whose evidence is a made-up reference is worse than an open
     * case, because it looks settled.
     */
    public function resolveAdjusted(
        string $actorId,
        string $caseId,
        string $compensatingPostingId,
        string $note,
    ): ReconciliationCase {
        if ($this->ledger->forCorrelation($compensatingPostingId) === []) {
            throw new PaymentsInvalidState(sprintf(
                'No ledger posting exists under correlation "%s". Post the compensating entries first, '
                .'then close the case against them.',
                $compensatingPostingId,
            ));
        }

        return $this->mutate($actorId, $caseId, 'finance.reconciliation_resolved_adjusted', function (ReconciliationCase $case) use ($actorId, $compensatingPostingId, $note): void {
            $case->resolveAdjusted($actorId, $compensatingPostingId, $note, $this->clock->now());
        });
    }

    /**
     * @param callable(ReconciliationCase): void $change
     */
    private function mutate(string $actorId, string $caseId, string $action, callable $change): ReconciliationCase
    {
        return $this->transactions->atomic(function () use ($actorId, $caseId, $action, $change): ReconciliationCase {
            $case = $this->cases->findByIdForUpdate($caseId)
                ?? throw PaymentsNotFound::of('reconciliation case', $caseId);

            $before = $case->state();
            $expected = $case->version();

            $change($case);

            $this->cases->update($case, $expected);

            $this->events->publish(new FinancialActionAudited(
                actorId: $actorId,
                auditAction: $action,
                subjectType: 'reconciliation_case',
                subjectId: $case->id(),
                amountMinor: $case->differenceMinor(),
                currency: $case->expected()->currency,
                reason: $case->resolutionNote(),
                correlationId: CorrelationContext::forAudit(),
                idempotencyKey: null,
                beforeState: $before->value,
                afterState: $case->state()->value,
                result: 'succeeded',
            ));

            return $case;
        });
    }
}
