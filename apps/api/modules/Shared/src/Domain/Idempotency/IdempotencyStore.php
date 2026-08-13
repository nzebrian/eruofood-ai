<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Idempotency;

/**
 * Makes a money-moving operation safe to retry.
 *
 * A client that times out cannot tell whether its request was applied, so it
 * retries — and without a guard the retry charges the card, ships the order or
 * pays out a second time. The caller supplies a key; the first request under
 * that key executes and its result is stored, and every later request with the
 * same key returns that stored result instead of executing again.
 *
 * The guarantee comes from a unique index, not from a read-then-write check, so
 * two *simultaneous* retries are arbitrated by the database: exactly one claims
 * the key and the other is told the work is already in flight.
 */
interface IdempotencyStore
{
    /**
     * Execute $work at most once for ($scope, $key) and return its result.
     *
     * A repeat with a matching $requestHash replays the stored result. A repeat
     * with a different $requestHash, or one arriving while the first is still
     * running, raises {@see \EruoFood\Shared\Domain\Exception\IdempotencyConflict}.
     *
     * When $key is null the operation is not idempotent and $work simply runs —
     * this lets callers pass an optional client-supplied key straight through.
     *
     * If $work throws, the claim is released so a corrected retry can proceed.
     *
     * @param callable():array<string, mixed> $work
     * @return IdempotentResult the result, flagged as fresh or replayed
     */
    public function execute(string $scope, ?string $key, string $requestHash, callable $work): IdempotentResult;

    /** Discard expired keys. Returns how many were removed. */
    public function purgeExpired(): int;
}
