<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Idempotency;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Exception\IdempotencyConflict;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\Idempotency\IdempotentResult;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Database-backed {@see IdempotencyStore}.
 *
 * The claim is written and committed *before* the work runs and outside any
 * transaction the work opens. That ordering is the whole point: if the claim
 * shared the work's transaction, a rollback would erase the evidence that the
 * request was ever seen, and the retry would execute again.
 *
 * Three outcomes follow from one INSERT:
 *
 * - insert succeeds — this caller owns the key and runs the work;
 * - insert violates the unique index and the stored row is `completed` — an
 *   earlier identical request already finished, so its response is replayed;
 * - insert violates the unique index and the stored row is `in_progress` — a
 *   concurrent duplicate; the caller is told to retry rather than double-apply.
 *
 * A crash between claim and completion leaves the key `in_progress`, which
 * blocks retries until `expires_at`. That is deliberate: refusing a retry we
 * cannot prove is safe beats risking a second money movement.
 *
 * ## What the principal is, and is not
 *
 * `$principalId` is written to `user_id` so a claim can be attributed and
 * erased. It is **not** part of the unique index, and adding it there would be
 * a mistake: `user_id` is null for every scope that predates M41, and
 * PostgreSQL treats nulls in a unique index as distinct — so widening the
 * constraint would silently remove the uniqueness guarantee from all of them.
 * A caller that needs one principal's keys to be independent of another's binds
 * the principal into the key value itself; see
 * {@see \EruoFood\Shared\Interface\Http\Concerns\UsesIdempotencyKey::principalScopedIdempotencyKey()}.
 */
final readonly class EloquentIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private Clock $clock,
        private int $ttlSeconds = 86400,
    ) {
    }

    public function execute(string $scope, ?string $key, string $requestHash, callable $work, ?string $principalId = null): IdempotentResult
    {
        if ($key === null) {
            return IdempotentResult::fresh($work());
        }

        $replay = $this->claim($scope, $key, $requestHash, $principalId);
        if ($replay !== null) {
            return $replay;
        }

        try {
            $value = $work();
        } catch (Throwable $e) {
            // Release the claim so a corrected or retried request can proceed.
            // Only the failing caller's own claim is removed.
            IdempotencyKeyModel::query()
                ->where('scope', $scope)
                ->where('idempotency_key', $key)
                ->where('state', IdempotencyKeyModel::STATE_IN_PROGRESS)
                ->delete();

            throw $e;
        }

        IdempotencyKeyModel::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->update([
                'state' => IdempotencyKeyModel::STATE_COMPLETED,
                'response_snapshot' => json_encode($value, JSON_THROW_ON_ERROR),
                'completed_at' => $this->clock->now(),
            ]);

        return IdempotentResult::fresh($value);
    }

    public function purgeExpired(): int
    {
        return IdempotencyKeyModel::query()->where('expires_at', '<', $this->clock->now())->delete();
    }

    /**
     * Take ownership of the key, or return the result to replay.
     *
     * @return IdempotentResult|null null when this caller owns the claim and
     *                               should run the work
     */
    private function claim(string $scope, string $key, string $requestHash, ?string $principalId): ?IdempotentResult
    {
        $now = $this->clock->now();

        if ($this->tryInsertClaim($scope, $key, $requestHash, $now, $principalId)) {
            return null;
        }

        $existing = IdempotencyKeyModel::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing === null) {
            // The holder released or purged the row between our insert and this
            // read. Treating it as in-flight is the safe answer: a retry costs
            // one round trip, a wrong guess costs a duplicate payment.
            throw IdempotencyConflict::inFlight($key);
        }

        // An abandoned claim (crashed mid-flight) becomes reclaimable once it
        // expires, so a stuck key cannot block the caller forever.
        if ($existing->state === IdempotencyKeyModel::STATE_IN_PROGRESS
            && $existing->expires_at < $now) {
            $existing->delete();

            if ($this->tryInsertClaim($scope, $key, $requestHash, $now, $principalId)) {
                return null;
            }

            // Another caller reclaimed it first.
            throw IdempotencyConflict::inFlight($key);
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw IdempotencyConflict::reused($key);
        }

        if ($existing->state !== IdempotencyKeyModel::STATE_COMPLETED) {
            throw IdempotencyConflict::inFlight($key);
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = $existing->response_snapshot ?? [];

        return IdempotentResult::replayed($snapshot);
    }

    /**
     * Attempt the claim insert, reporting whether this caller won the key.
     *
     * The insert is wrapped in its own transaction, which matters on
     * PostgreSQL: a constraint violation there aborts the *entire* enclosing
     * transaction, so every later statement fails with "current transaction is
     * aborted" — including the SELECT we need in order to answer the caller.
     * Nesting turns the wrapper into a SAVEPOINT, so a losing insert rolls back
     * only itself and the surrounding work carries on. On engines without that
     * behaviour the wrapper is simply a short transaction.
     *
     * Impure by design: it writes a row, so two calls with the same arguments
     * can legitimately return different answers (the second losing the race, or
     * winning after an expired claim was cleared).
     *
     * @phpstan-impure
     */
    private function tryInsertClaim(string $scope, string $key, string $requestHash, DateTimeImmutable $now, ?string $principalId): bool
    {
        try {
            DB::transaction(function () use ($scope, $key, $requestHash, $now, $principalId): void {
                $this->insertClaim($scope, $key, $requestHash, $now, $principalId);
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            // Someone else holds the key.
            return false;
        }
    }

    private function insertClaim(string $scope, string $key, string $requestHash, DateTimeImmutable $now, ?string $principalId): void
    {
        $row = new IdempotencyKeyModel();
        $row->id = (string) Str::orderedUuid();
        $row->scope = $scope;
        $row->idempotency_key = $key;
        $row->request_hash = $requestHash;
        $row->user_id = $principalId;
        $row->state = IdempotencyKeyModel::STATE_IN_PROGRESS;
        $row->created_at = $now;
        $row->expires_at = $now->modify(sprintf('+%d seconds', $this->ttlSeconds));
        $row->save();
    }
}
