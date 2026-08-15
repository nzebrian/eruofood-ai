<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Request;

use DateTimeImmutable;

/** Persistence port for {@see DispatchRequest} and its {@see DispatchAttempt} history. */
interface DispatchRequestRepository
{
    public function nextIdentity(): string;

    public function find(string $id): ?DispatchRequest;

    /**
     * The live search for a delivery, if one is running.
     *
     * Mirrors the partial unique index: at most one request per delivery is in
     * `pending` or `dispatching` at a time.
     */
    public function liveForDelivery(string $deliveryId): ?DispatchRequest;

    /**
     * Take the row's lock before reading it.
     *
     * `SELECT … FOR UPDATE`. Two workers deciding "may this request be
     * attempted again?" from an unlocked read will both decide yes, and the
     * customer gets two riders. Optimistic versioning catches the second write;
     * this stops the second worker from doing the work at all.
     *
     * Must be called inside a transaction — outside one the lock is released
     * immediately and the call is worse than useless, because it looks safe.
     */
    public function lockForUpdate(string $id): ?DispatchRequest;

    /**
     * Requests a worker may claim, oldest first.
     *
     * @return list<DispatchRequest>
     */
    public function claimable(int $limit = 20): array;

    /**
     * Live requests whose time budget has run out.
     *
     * @return list<DispatchRequest>
     */
    public function timedOut(DateTimeImmutable $now, int $limit = 100): array;

    /**
     * Searches that ended without a rider, most recent first.
     *
     * What an operator opens when the alert fires. Ordered by when they failed
     * rather than when they started, because the question is "what has just
     * gone wrong?".
     *
     * @return list<DispatchRequest>
     */
    public function failed(int $limit = 50): array;

    /** @return list<DispatchRequest> */
    public function forOrder(string $orderId): array;

    public function save(DispatchRequest $request): void;

    /**
     * Append one round's record.
     *
     * Append-only: there is no update, by design. A dispatch history that can be
     * tidied up after the fact is not a history.
     */
    public function recordAttempt(DispatchAttempt $attempt): void;

    /**
     * The rounds tried for a request, in order.
     *
     * @return list<DispatchAttempt>
     */
    public function attemptsFor(string $requestId): array;
}
