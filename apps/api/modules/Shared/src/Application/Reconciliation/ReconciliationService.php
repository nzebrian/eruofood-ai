<?php

declare(strict_types=1);

namespace EruoFood\Shared\Application\Reconciliation;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Lifecycle\ServerPhase;
use EruoFood\Shared\Domain\Reconciliation\ReconcilableOperation;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;

/**
 * Answers "what happened to the requests I sent?" after a client comes back.
 *
 * ## Built on the idempotency store, not a new table
 *
 * M23 already records every money-moving request: scope, key, who sent it,
 * whether it completed, and a snapshot of the response. That is exactly the
 * ledger reconciliation needs, and adding a parallel one would create two
 * records of the same fact that could disagree.
 *
 * ## Ownership is not optional
 *
 * An idempotency key is a client-chosen string. If this endpoint answered on
 * key alone, anybody could enumerate keys and learn the outcome of other
 * people's payments — and a guessable key would hand over the response snapshot
 * with it. So every lookup is scoped to the authenticated user, and a key
 * belonging to somebody else is reported exactly as one that never existed:
 * `NeverReceived`.
 *
 * That identical answer is deliberate. Distinguishing "not yours" from "not
 * found" would confirm which keys exist. The cost is that a client
 * reconciling with the wrong account is told it is safe to resend — which is
 * true for *that* account, since nothing of theirs took effect.
 *
 * ## What it never does
 *
 * It does not re-run work, cancel anything, or change state. Reconciliation is
 * a read. A recovery path that mutates is a recovery path that can make things
 * worse than the crash did.
 */
final readonly class ReconciliationService
{
    /**
     * Reconcile one operation for one user.
     *
     * @param string $userId the *authenticated* account, never a request field
     */
    public function reconcile(string $userId, string $scope, string $idempotencyKey): ReconcilableOperation
    {
        $row = IdempotencyKeyModel::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $idempotencyKey)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return ReconcilableOperation::neverReceived($idempotencyKey);
        }

        if ($row->state === IdempotencyKeyModel::STATE_IN_PROGRESS) {
            // A claim with no result. Either the work is genuinely running, or
            // the process that held it died — which is what a crash mid-payment
            // leaves behind. Both mean "wait, do not resend": the claim still
            // blocks a duplicate, and it expires on its own.
            return ReconcilableOperation::inFlight(
                $idempotencyKey,
                DateTimeImmutable::createFromInterface($row->created_at),
            );
        }

        $snapshot = $row->response_snapshot ?? [];

        return ReconcilableOperation::known(
            idempotencyKey: $idempotencyKey,
            phase: $this->phaseFrom($snapshot),
            contextStatus: $this->stringFrom($snapshot, 'status') ?? 'completed',
            resourceType: $this->stringFrom($snapshot, 'resource_type') ?? $scope,
            resourceId: $this->stringFrom($snapshot, 'id') ?? $idempotencyKey,
            lastUpdatedAt: DateTimeImmutable::createFromInterface($row->completed_at ?? $row->created_at),
        );
    }

    /**
     * Reconcile several at once.
     *
     * A client that was offline for a while has a queue, not one request, and
     * making it issue six round trips on a connection that just came back is
     * how reconciliation itself becomes the thing that fails.
     *
     * @param list<array{scope: string, key: string}> $operations
     * @return list<ReconcilableOperation>
     */
    public function reconcileMany(string $userId, array $operations): array
    {
        return array_map(
            fn (array $operation): ReconcilableOperation => $this->reconcile(
                $userId,
                $operation['scope'],
                $operation['key'],
            ),
            $operations,
        );
    }

    /**
     * The phase recorded in the stored response, defaulting to confirmed.
     *
     * A completed claim whose snapshot carries no phase is one that finished
     * without error — that is what "completed" means in the store. The default
     * is safe here precisely because the alternative branch (`in_progress`) was
     * already taken above.
     *
     * @param array<string, mixed> $snapshot
     */
    private function phaseFrom(array $snapshot): ServerPhase
    {
        $phase = $snapshot['phase'] ?? null;

        return is_string($phase)
            ? (ServerPhase::tryFrom($phase) ?? ServerPhase::Confirmed)
            : ServerPhase::Confirmed;
    }

    /** @param array<string, mixed> $snapshot */
    private function stringFrom(array $snapshot, string $key): ?string
    {
        $value = $snapshot[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
